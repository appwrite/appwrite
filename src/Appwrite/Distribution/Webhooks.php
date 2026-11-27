<?php

namespace Appwrite\Distribution;

use Appwrite\Extend\Exception;
use Utopia\Cache\Cache;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception as DatabaseException;
use Utopia\Database\Query;
use Utopia\Distribution\Exception as StoreException;
use Utopia\Distribution\Store;
use Utopia\Distribution\Store\Apple\Webhook;
use Utopia\Span\Span;
use Utopia\System\System;

/**
 * App Store Connect webhooks, one per located iOS target. Apple never returns a
 * webhook's secret, so each target's entry ({id, appId, secret}) is kept in the
 * connection's encrypted credentials under `webhooks`.
 */
readonly class Webhooks
{
    private const int TTL = 86400;

    private const int BYTES = 32;

    private const string CREDENTIALS = 'webhooks';

    private const string PATH = '/v1/distribution/webhooks/apple';

    private const string VERSION = '/v1';

    private const string CACHE = 'distribution:webhook:apple:';

    private const string SEEN = '1';

    public function __construct(
        private Database $dbForProject,
        private Stores $stores,
    ) {
    }

    public function register(Document $project, Document $connection, Document $target, string $appId): void
    {
        $entry = self::entry($connection, $target->getId());
        $registered = $entry['appId'] === $appId;

        if ($registered && $entry['id'] !== '') {
            return;
        }

        try {
            $store = $this->stores->connection($connection);

            if (!$store instanceof Store\Apple) {
                return;
            }

            $url = self::url($project, $connection, $target);
            $reused = $registered && $entry['secret'] !== '';
            $secret = $reused ? $entry['secret'] : \bin2hex(\random_bytes(self::BYTES));

            if (!$reused) {
                $this->record($connection, $target, ['appId' => $appId, 'secret' => $secret]);
            }

            foreach ($store->listWebhooks($appId) as $webhook) {
                if ($webhook->url === $url) {
                    $store->deleteWebhook($webhook->id);
                }
            }

            $webhook = $store->createWebhook($appId, $url, $secret);
            $this->record($connection, $target, ['id' => $webhook->id, 'appId' => $appId, 'secret' => $secret]);
        } catch (StoreException|DatabaseException|Exception $error) {
            // Polling stays the fallback: a webhook that cannot be registered never fails the submission.
            Span::add('webhook.error', $error->getMessage());
        }
    }

    public function unregister(Document $target): void
    {
        $connectionId = (string) $target->getAttribute('connectionId', '');

        if ($connectionId === '') {
            return;
        }

        try {
            $connection = $this->dbForProject->getDocument('distributionConnections', $connectionId);
            $webhooks = self::webhooks($connection);

            if ($connection->isEmpty() || !\array_key_exists($target->getId(), $webhooks)) {
                return;
            }

            $entry = self::entry($connection, $target->getId());
            unset($webhooks[$target->getId()]);
            $this->save($connection, $webhooks);

            if ($entry['id'] === '') {
                return;
            }

            $store = $this->stores->connection($connection);

            if ($store instanceof Store\Apple) {
                $store->deleteWebhook($entry['id']);
            }
        } catch (StoreException|DatabaseException|Exception $error) {
            // An undeleted webhook only fails verification once its entry is gone.
            Span::add('webhook.error', $error->getMessage());
        }
    }

    public static function url(Document $project, Document $connection, Document $target): string
    {
        $endpoint = System::getEnv('_APP_VCS_WEBHOOK_URL', '');

        if ($endpoint === '') {
            $protocol = System::getEnv('_APP_OPTIONS_FORCE_HTTPS') === 'disabled' ? 'http' : 'https';
            $endpoint = $protocol . '://' . System::getEnv('_APP_DOMAIN', 'localhost');
        }

        $endpoint = \rtrim($endpoint, '/');

        if (\str_ends_with($endpoint, self::VERSION)) {
            $endpoint = \substr($endpoint, 0, -\strlen(self::VERSION));
        }

        return $endpoint . self::PATH
            . '/' . \rawurlencode($connection->getId())
            . '/' . \rawurlencode($target->getId())
            . '?' . \http_build_query(['project' => $project->getId()]);
    }

    public static function verify(Document $connection, string $targetId, string $body, string $signature): bool
    {
        return Webhook::verify($body, self::entry($connection, $targetId)['secret'], $signature);
    }

    public static function isDuplicate(Cache $cache, Document $project, string $connectionId, string $targetId, string $eventId): bool
    {
        return $cache->load(self::key($project, $connectionId, $targetId, $eventId), self::TTL) !== false;
    }

    public static function remember(Cache $cache, Document $project, string $connectionId, string $targetId, string $eventId): void
    {
        $cache->save(self::key($project, $connectionId, $targetId, $eventId), self::SEEN, ttl: self::TTL);
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function webhooks(Document $connection): array
    {
        $credentials = $connection->getAttribute('credentials', []);
        $webhooks = \is_array($credentials) ? ($credentials[self::CREDENTIALS] ?? null) : null;

        return \is_array($webhooks) ? $webhooks : [];
    }

    /**
     * @return array{id: string, appId: string, secret: string}
     */
    private static function entry(Document $connection, string $targetId): array
    {
        $entry = self::webhooks($connection)[$targetId] ?? null;
        $entry = \is_array($entry) ? $entry : [];

        return [
            'id' => \is_string($entry['id'] ?? null) ? $entry['id'] : '',
            'appId' => \is_string($entry['appId'] ?? null) ? $entry['appId'] : '',
            'secret' => \is_string($entry['secret'] ?? null) ? $entry['secret'] : '',
        ];
    }

    private static function key(Document $project, string $connectionId, string $targetId, string $eventId): string
    {
        return self::CACHE . $project->getSequence() . ':' . \hash('sha256', "{$connectionId}:{$targetId}:{$eventId}");
    }

    /**
     * @param array<string, string> $entry
     */
    private function record(Document $connection, Document $target, array $entry): void
    {
        $current = $this->dbForProject->getDocument('distributionConnections', $connection->getId());

        if ($current->isEmpty()) {
            return;
        }

        $webhooks = $this->prune($current, self::webhooks($current), $target->getId());
        $webhooks[$target->getId()] = $entry;

        $this->save($current, $webhooks);
    }

    /**
     * @param array<array-key, mixed> $webhooks
     * @return array<array-key, mixed>
     */
    private function prune(Document $connection, array $webhooks, string $targetId): array
    {
        $candidates = \array_values(\array_filter(
            \array_map(\strval(...), \array_keys($webhooks)),
            static fn (string $id): bool => $id !== $targetId,
        ));

        if ($candidates === []) {
            return $webhooks;
        }

        $targets = $this->dbForProject->find('distributionTargets', [
            Query::equal('$id', $candidates),
            Query::equal('connectionInternalId', [$connection->getSequence()]),
            Query::select(['$id']),
            Query::limit(\count($candidates)),
        ]);

        $kept = [$targetId, ...\array_map(static fn (Document $target): string => $target->getId(), $targets)];

        return \array_intersect_key($webhooks, \array_flip($kept));
    }

    /**
     * @param array<array-key, mixed> $webhooks
     */
    private function save(Document $current, array $webhooks): void
    {
        $credentials = $current->getAttribute('credentials', []);
        $credentials = \is_array($credentials) ? $credentials : [];
        $credentials[self::CREDENTIALS] = $webhooks;

        $this->dbForProject->updateDocument('distributionConnections', $current->getId(), new Document([
            'credentials' => $credentials,
        ]));
    }
}
