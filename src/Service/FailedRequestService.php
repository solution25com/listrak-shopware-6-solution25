<?php
declare(strict_types=1);

namespace Listrak\Service;

use Listrak\Core\Content\FailedRequest\FailedRequestCollection;
use Listrak\Core\Content\FailedRequest\FailedRequestEntity;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\DataAbstractionLayerException;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\Framework\Uuid\Uuid;

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
        private readonly ListrakConfigService $listrakConfigService,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @throws DataAbstractionLayerException
     */
    public function retry(Context $context): void
    {
        if ($this->listrakConfigService->getConfig('enableOrderSync') || $this->listrakConfigService->getConfig('enableCustomerSync')) {
            foreach ($this->findEntries($context) as $failedRequestEntry) {
                $this->listrakApiService->request(
                    ['url' => $failedRequestEntry->getEndpoint(), 'method' => $failedRequestEntry->getMethod()],
                    $failedRequestEntry->getOptions(),
                    $context,
                    $failedRequestEntry
                );
            }
        } else {
            $this->logger->debug('Failed request retry skipped — sync not enabled');
        }
    }

    /**
     * @param array<string,string[]> $options
     */
    public function saveRequestToFailedRequests(
        string $endpoint,
        string $method,
        array $options,
        string $response,
        ?Context $context = null
    ): void {
        if ($context !== null) {
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

    public function flushFailedRequests(?Context $context): void
    {
        if ($context !== null && !empty($this->failedRequests)) {
            $data = array_map(fn ($entity) => [
                'id' => $entity->getId(),
                'retryCount' => $entity->getRetryCount(),
                'lastRetryAt' => $entity->getLastRetryAt(),
                'method' => $entity->getMethod(),
                'endpoint' => $entity->getEndpoint(),
                'response' => $entity->getResponse(),
                'options' => $entity->getOptions(),
            ], $this->failedRequests);

            $this->failedRequestRepository->upsert($data, $context);
        }
    }

    public function removeFromFailedRequests(?Context $context, ?FailedRequestEntity $failedRequestEntity): void
    {
        if ($context && $failedRequestEntity !== null) {
            $this->failedRequestRepository->delete([
                ['id' => $failedRequestEntity->getId()],
            ], $context);
        }
    }

    /**
     * @throws DataAbstractionLayerException
     */
    private function findEntries(Context $context): FailedRequestCollection
    {
        $criteria = new Criteria();
        $criteria->addFilter(new RangeFilter('retryCount', [
            RangeFilter::LT => self::MAX_RETRY_COUNT,
        ]));

        return $this->failedRequestRepository->search($criteria, $context)->getEntities();
    }
}
