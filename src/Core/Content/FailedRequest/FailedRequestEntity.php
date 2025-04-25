<?php declare(strict_types=1);

namespace Listrak\Core\Content\FailedRequest;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;

class FailedRequestEntity extends Entity
{
    use EntityIdTrait;

    /**
     * @var int
     */
    protected $retryCount = 0;

    /**
     * @var \DateTime|null
     */
    protected $lastRetryAt;

    /**
     * @var string
     */
    protected $response;

    /**
     * @var string
     */
    protected $salesChannelId;

    /**
     * @var array<string,mixed>
     */
    protected $options;

    /**
     * @var string
     */
    protected $endpoint;

    /**
     * @var string
     */
    protected $method;

    /**
     * @var SalesChannelEntity|null
     */
    protected $salesChannel;

    public function getRetryCount(): int
    {
        return $this->retryCount;
    }

    public function setRetryCount(int $retryCount): void
    {
        $this->retryCount = $retryCount;
    }

    public function getLastRetryAt(): ?\DateTime
    {
        return $this->lastRetryAt;
    }

    public function setLastRetryAt(?\DateTime $lastRetryAt): void
    {
        $this->lastRetryAt = $lastRetryAt;
    }

    /**
     * @return array<string,mixed>
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * @param array<string,mixed> $options
     */
    public function setOptions(array $options): void
    {
        $this->options = $options;
    }

    public function getResponse(): string
    {
        return $this->response;
    }

    public function setResponse(string $response): void
    {
        $this->response = $response;
    }

    public function getSalesChannelId(): string
    {
        return $this->salesChannelId;
    }

    public function setSalesChannelId(string $salesChannelId): void
    {
        $this->salesChannelId = $salesChannelId;
    }

    /**
     * @return string $endpoint
     */
    public function getEndpoint(): string
    {
        return $this->endpoint;
    }

    public function setEndpoint(string $endpoint): void
    {
        $this->endpoint = $endpoint;
    }

    /**
     * @return string $method
     */
    public function getMethod(): string
    {
        return $this->method;
    }

    public function setMethod(string $method): void
    {
        $this->method = $method;
    }
}
