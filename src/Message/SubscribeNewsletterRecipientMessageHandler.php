<?php

declare(strict_types=1);

namespace Listrak\Message;

use Listrak\Service\DataMappingService;
use Listrak\Service\ListrakApiService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Newsletter\Aggregate\NewsletterRecipient\NewsletterRecipientEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\ExceptionInterface;

#[AsMessageHandler]
final class SubscribeNewsletterRecipientMessageHandler
{
    public function __construct(
        private readonly EntityRepository $newsletterRecipientRepository,
        private readonly DataMappingService $dataMappingService,
        private readonly ListrakApiService $listrakApiService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(SubscribeNewsletterRecipientMessage $message): void
    {
        $this->logger->debug('Subscribe newsletter recipient message started.');
        $context = $message->getContext();
        $newsletterRecipientId = $message->getNewsletterRecipientId();
        try {
            $criteria = new Criteria([$newsletterRecipientId]);
            /** @var NewsletterRecipientEntity|null $newsletterRecipient */
            $newsletterRecipient = $this->newsletterRecipientRepository->search($criteria, $context)->first();
            if ($newsletterRecipient !== null) {
                $data = $this->dataMappingService->mapContactData($newsletterRecipient);
                $this->listrakApiService->createorUpdateContact($data, $context);
            }
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
        } catch (ExceptionInterface $e) {
            $this->logger->error($e->getMessage());
        }
    }
}
