<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Workers\Fixture;

use Appwrite\Event\Publisher\Notification as NotificationPublisher;
use Appwrite\Event\Publisher\Usage as UsagePublisher;
use Appwrite\Platform\Workers\Webhooks;
use Override;
use Utopia\Database\Database;
use Utopia\Database\Document;

final class TestingWebhooks extends Webhooks
{
    /**
     * @var array<string, int>
     */
    public array $deliveries = [];

    /**
     * @var array<string, string>
     */
    public array $failures = [];

    /**
     * @var list<string>
     */
    public array $envelopes = [];

    #[Override]
    protected function execute(
        array $events,
        string $payload,
        Document $webhook,
        Document $user,
        Document $project,
        Database $dbForPlatform,
        NotificationPublisher $publisherForNotifications,
        UsagePublisher $publisherForUsage,
        array $plan,
        string $envelopeId,
    ): ?string {
        $webhookId = $webhook->getId();
        $this->deliveries[$webhookId] = ($this->deliveries[$webhookId] ?? 0) + 1;
        $this->envelopes[] = $envelopeId;

        return $this->failures[$webhookId] ?? null;
    }

    /**
     * @return list<string>
     */
    public function headers(
        array $events,
        string $payload,
        Document $webhook,
        Document $user,
        Document $project,
        string $envelopeId,
    ): array {
        return $this->buildHeaders($events, $payload, $webhook, $user, $project, $envelopeId);
    }
}
