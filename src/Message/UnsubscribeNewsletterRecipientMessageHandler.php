<?php

declare(strict_types=1);

namespace Listrak\Message;

use Listrak\Service\ListrakApiService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Newsletter\Aggregate\NewsletterRecipient\NewsletterRecipientEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\ExceptionInterface;

#[AsMessageHandler]
final class UnsubscribeNewsletterRecipientMessageHandler
{
    public function __construct(
        private readonly EntityRepository $newsletterRecipientRepository,
        private readonly ListrakApiService $listrakApiService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(UnsubscribeNewsletterRecipientMessage $message): void
    {
        $this->logger->debug('Listrak unsubscribe newsletter recipient started.');
        $context = $message->getContext();
        $newsletterRecipientId = $message->getNewsletterRecipientId();
        try {
            $criteria = new Criteria([$newsletterRecipientId]);
            /** @var NewsletterRecipientEntity|null $newsletterRecipient */
            $newsletterRecipient = $this->newsletterRecipientRepository->search($criteria, $context)->first();
            $data = [
                'emailAddress' => $newsletterRecipient->getEmail(),
                'subscriptionState' => 'Unsubscribed',
            ];

            $this->listrakApiService->createOrUpdateContact($data, $context);
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
        } catch (ExceptionInterface $e) {
            $this->logger->error($e->getMessage());
        }
    }
}
