<?php declare(strict_types=1);

namespace Listrak\Core\Content\Flow\Dispatching\Action;

use Doctrine\DBAL\Exception;
use Dotdigital\Flow\Core\Framework\Event\ListrakMailAware;
use Listrak\Event\ListrakTemplateCustomizationEvent;
use Listrak\Service\DataMappingService;
use Listrak\Service\ListrakApiService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Flow\Dispatching\Action\FlowAction;
use Shopware\Core\Content\Flow\Dispatching\Action\FlowMailVariables;
use Shopware\Core\Content\Flow\Dispatching\DelayableAction;
use Shopware\Core\Content\Flow\Dispatching\StorableFlow;
use Shopware\Core\Content\MailTemplate\Exception\MailEventConfigurationException;
use Shopware\Core\Content\MailTemplate\Exception\SalesChannelNotFoundException;
use Shopware\Core\Framework\Adapter\Twig\StringTemplateRenderer;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException;
use Shopware\Core\Framework\Event\EventData\MailRecipientStruct;
use Shopware\Core\Framework\Event\MailAware;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class ListrakSendMailAction extends FlowAction implements DelayableAction
{
    final public const ACTION_NAME = 'action.listrak.mail.send';
    private const RECIPIENT_CONFIG_ADMIN = 'admin';
    private const RECIPIENT_CONFIG_CUSTOM = 'custom';
    private const RECIPIENT_CONFIG_CONTACT_FORM_MAIL = 'contactFormMail';

    /**
     * @internal
     */
    public function __construct(
        private readonly ListrakApiService $listrakApiService,
        private readonly DataMappingService $dataMappingService,
        private readonly StringTemplateRenderer $templateRenderer,
        private readonly EventDispatcherInterface $eventDispatcher,
        private LoggerInterface $logger
    ) {
    }

    public static function getName(): string
    {
        return self::ACTION_NAME;
    }

    /**
     * @return array<string>
     */
    public function requirements(): array
    {
        return [ListrakMailAware::class, MailAware::class];
    }

    /**
     * @throws MailEventConfigurationException
     * @throws SalesChannelNotFoundException
     * @throws InconsistentCriteriaIdsException
     */
    public function handleFlow(StorableFlow $flow): void
    {
        if (!$flow->hasData(MailAware::MAIL_STRUCT) || !$flow->hasData(MailAware::SALES_CHANNEL_ID)) {
            throw new MailEventConfigurationException('Not have data from MailAware', $flow::class);
        }

        $eventConfig = $flow->getConfig();
        if (empty($eventConfig['recipient'])) {
            throw new MailEventConfigurationException(
                'The recipient value in the flow action configuration is missing.',
                $flow::class
            );
        }
        if (empty($eventConfig['transactionalMessageId'])) {
            throw new MailEventConfigurationException(
                'The transactional message ID value in the flow action configuration is missing.',
                $flow::class
            );
        }
        $transactionalMessageId = $eventConfig['transactionalMessageId'];
        $profileFields = $eventConfig['profileFields'] ?? '';

        /** @var MailRecipientStruct $mailStruct */
        $mailStruct = $flow->getData(MailAware::MAIL_STRUCT);

        $recipients = $this->getRecipients(
            $eventConfig['recipient'],
            $mailStruct->getRecipients(),
            $flow->getData(FlowMailVariables::CONTACT_FORM_DATA, []),
        );

        if (empty($recipients)) {
            return;
        }

        $templateData = [
            'eventName' => $flow->getName(),
            ...$flow->data(),
        ];

        $context = $flow->getContext();
        /** @var SalesChannelContext $channelContext */
        foreach ($profileFields as $key => $value) {
            $profileFields[$key] = $this->templateRenderer->render($value, $templateData, $context);
        }

        $fields = $this->dataMappingService->mapTemplateVariables($profileFields) ?? [];
        $event = new ListrakTemplateCustomizationEvent($fields, $flow);
        $this->eventDispatcher->dispatch($event, ListrakTemplateCustomizationEvent::NAME);
        $modifiedPayload = $event->getPayload();
        $data = $this->dataMappingService->mapTransactionalMessageData($recipients, $modifiedPayload);
        $this->listrakApiService->sendTransactionalMessage($transactionalMessageId, $data, $context);
    }

    /**
     * @param array<string, mixed> $recipients
     * @param array<string, string> $mailStructRecipients
     * @param array<int|string, mixed> $contactFormData
     *
     * @throws Exception
     *
     * @return array<int|string, string>
     */
    private function getRecipients(array $recipients, $mailStructRecipients, array $contactFormData): array
    {
        switch ($recipients['type']) {
            case self::RECIPIENT_CONFIG_CUSTOM:
                return $recipients['data'];
            case self::RECIPIENT_CONFIG_ADMIN:
                $admins = $this->connection->fetchAllAssociative(
                    'SELECT first_name, last_name, email FROM user WHERE admin = true'
                );
                $emails = [];
                foreach ($admins as $admin) {
                    $emails[$admin['email']] = $admin['first_name'] . ' ' . $admin['last_name'];
                }

                return $emails;
            case self::RECIPIENT_CONFIG_CONTACT_FORM_MAIL:
                if (empty($contactFormData)) {
                    return [];
                }

                if (!\array_key_exists('email', $contactFormData)) {
                    return [];
                }

                return [$contactFormData['email'] => ($contactFormData['firstName'] ?? '') . ' ' . ($contactFormData['lastName'] ?? '')];
            default:
                return $mailStructRecipients;
        }
    }
}
