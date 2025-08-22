<?php

declare(strict_types=1);

namespace Listrak\Service;

use Listrak\Core\Content\FailedRequest\FailedRequestCollection;
use Listrak\Core\Content\FailedRequest\FailedRequestEntity;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\DataAbstractionLayerException;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class FailedRequestService
{
    public const MAX_RETRY_COUNT = 3;

    private array $failedRequests = [];

    /**
     * @param EntityRepository<FailedRequestCollection> $failedRequestRepository
     */
    public function __construct(
        private readonly EntityRepository $failedRequestRepository,
        private ListrakApiService $listrakApiService,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @throws DataAbstractionLayerException
     */
    public function retry(SalesChannelContext $salesChannelContext): void
    {
        $this->logger->info('Retrying failed requests', [
            'salesChannelId' => $salesChannelContext->getSalesChannel()->getId(),
        ]);
        foreach ($this->findEntries($salesChannelContext) as $failedRequestEntry) {
            if ($failedRequestEntry->getOptions()) {
            }
            $this->listrakApiService->request(
                ['url' => $failedRequestEntry->getEndpoint(), 'method' => $failedRequestEntry->getMethod()],
                $failedRequestEntry->getOptions(),
                $salesChannelContext,
                $failedRequestEntry
            );
        }

        $this->flushFailedRequests($salesChannelContext);
    }

    /**
     * @param array<string,string[]> $options
     */
    public function saveRequestToFailedRequests(
        string $endpoint,
        string $method,
        array $options,
        string $response,
        ?SalesChannelContext $salesChannelContext = null
    ): void {
        if ($salesChannelContext !== null) {
            $entity = new FailedRequestEntity();
            $entity->setId(Uuid::randomHex());
            $entity->setResponse($response);
            $entity->setMethod($method);
            $entity->setEndpoint($endpoint);
            $entity->setOptions($options);
            $entity->setRetryCount(1);
            $entity->setLastRetryAt(new \DateTime('now'));

            $this->failedRequests[] = $entity;
        }
    }

    public function updateFailedRequest(FailedRequestEntity $entity): void
    {
        $entity->setRetryCount($entity->getRetryCount() + 1);
        $entity->setLastRetryAt(new \DateTime());

        $this->failedRequests[] = $entity;
    }

    public function flushFailedRequests(?SalesChannelContext $salesChannelContext): void
    {
        if ($salesChannelContext !== null && !empty($this->failedRequests)) {
            $data = array_map(fn ($entity) => [
                'id' => $entity->getId(),
                'retryCount' => $entity->getRetryCount(),
                'lastRetryAt' => $entity->getLastRetryAt(),
                'method' => $entity->getMethod(),
                'endpoint' => $entity->getEndpoint(),
                'response' => $entity->getResponse(),
                'options' => $entity->getOptions(),
            ], $this->failedRequests);

            $this->failedRequestRepository->upsert($data, $salesChannelContext->getContext());
            $this->failedRequests = [];
        }
    }

    public function removeFromFailedRequests(?SalesChannelContext $salesChannelContext, ?FailedRequestEntity $failedRequestEntity): void
    {
        if ($salesChannelContext && $failedRequestEntity !== null) {
            $this->failedRequestRepository->delete([
                ['id' => $failedRequestEntity->getId()],
            ], $salesChannelContext->getContext());
        }
    }

    /**
     * @throws DataAbstractionLayerException
     */
    private function findEntries(SalesChannelContext $salesChannelContext): FailedRequestCollection
    {
        $criteria = new Criteria();
        $criteria->addFilter(new RangeFilter('retryCount', [
            RangeFilter::LT => self::MAX_RETRY_COUNT,
        ]));

        return $this->failedRequestRepository->search($criteria, $salesChannelContext->getContext())->getEntities();
    }
}
