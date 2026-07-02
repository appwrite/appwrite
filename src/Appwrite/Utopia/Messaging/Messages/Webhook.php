<?php

namespace Appwrite\Utopia\Messaging\Messages;

use Utopia\Messaging\Message;

class Webhook implements Message
{
    private ?string $origin = null;

    /**
     * @param array<int, string> $urls
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     */
    public function __construct(
        protected array $urls,
        protected array $payload,
        protected ?string $signingSecret = null,
        protected array $headers = [],
        protected int $timeout = 30,
    ) {
    }

    /**
     * @return array<int, string>
     */
    public function getUrls(): array
    {
        return $this->urls;
    }

    /**
     * Alias used by the base adapter to bound max messages per request.
     *
     * @return array<int, string>
     */
    public function getTo(): array
    {
        return $this->urls;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getSigningSecret(): ?string
    {
        return $this->signingSecret;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getTimeout(): int
    {
        return $this->timeout;
    }

    public function setOrigin(?string $origin): self
    {
        $this->origin = $origin;

        return $this;
    }

    public function getOrigin(): ?string
    {
        return $this->origin;
    }
}
