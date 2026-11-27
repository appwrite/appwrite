<?php

declare(strict_types=1);

namespace Tests\Unit\Distribution\Fakes;

use Utopia\Distribution\App;
use Utopia\Distribution\Credential;
use Utopia\Distribution\Store\Apple;
use Utopia\Distribution\Store\Apple\Webhook;
use Utopia\Distribution\Tests\Transport;

final class RecordingApple extends Apple
{
    /**
     * @var list<string>
     */
    public array $calls = [];

    /**
     * @var list<array{appId: string, url: string, secret: string, events: list<string>}>
     */
    public array $created = [];

    /**
     * @var list<string>
     */
    public array $deleted = [];

    /**
     * @var array<string, list<Webhook>>
     */
    public array $webhooks = [];

    public ?\Throwable $failure = null;

    public ?\Throwable $deleteFailure = null;

    public function __construct(#[\SensitiveParameter] string $privateKey)
    {
        parent::__construct(new Credential\Apple('KEY1234567', 'issuer-1', $privateKey), new Transport());
    }

    #[\Override]
    public function app(string $identifier): App
    {
        $this->calls[] = 'app';

        return new App('store-app', $identifier);
    }

    #[\Override]
    public function createWebhook(
        string $appId,
        string $url,
        #[\SensitiveParameter]
        string $secret,
        array $events = Webhook::EVENTS,
        string $name = Webhook::NAME,
    ): Webhook {
        $this->calls[] = 'createWebhook';

        if ($this->failure !== null) {
            throw $this->failure;
        }

        $this->created[] = ['appId' => $appId, 'url' => $url, 'secret' => $secret, 'events' => $events];
        $webhook = new Webhook('webhook-' . \count($this->created), $name, $url, $events, true);
        $this->webhooks[$appId][] = $webhook;

        return $webhook;
    }

    #[\Override]
    public function listWebhooks(string $appId): array
    {
        $this->calls[] = 'listWebhooks';

        return $this->webhooks[$appId] ?? [];
    }

    #[\Override]
    public function deleteWebhook(string $webhookId): void
    {
        $this->calls[] = 'deleteWebhook';

        if ($this->deleteFailure !== null) {
            $failure = $this->deleteFailure;
            $this->deleteFailure = null;

            throw $failure;
        }

        $this->deleted[] = $webhookId;

        foreach ($this->webhooks as $appId => $webhooks) {
            $this->webhooks[$appId] = \array_values(\array_filter($webhooks, static fn (Webhook $webhook): bool => $webhook->id !== $webhookId));
        }
    }
}
