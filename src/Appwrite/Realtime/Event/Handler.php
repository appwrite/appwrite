<?php

namespace Appwrite\Realtime\Event;

use Appwrite\Extend\Exception as AppwriteException;
use Appwrite\Messaging\Adapter\Realtime;
use Appwrite\Realtime\EventTailRegistry;
use Appwrite\Realtime\Usage;
use Appwrite\Utopia\Database\Documents\User;
use Swoole\Coroutine\Channel;
use Swoole\Table;
use Utopia\Console\Console;
use Utopia\Database\Document;
use Utopia\Database\PermissionType;
use Utopia\Database\Query;
use Utopia\Span\Span;
use Utopia\System\System;
use Utopia\Telemetry\Counter;
use Utopia\Telemetry\Histogram;
use Utopia\Telemetry\ObservableGauge;
use Utopia\Telemetry\UpDownCounter;
use Utopia\WebSocket\Server;

/** Handles the realtime pub/sub firehose: permission changes, fan-out, closes and the console tail. */
class Handler
{
    // The step the event being handled is in, and since when.
    private string $stage = 'idle';
    private float $stageSince;

    // Connections the event being handled was sent to.
    private int $delivered = 0;

    /**
     * @param array<string, string> $workerAttributes
     */
    public function __construct(
        private readonly Server $server,
        private readonly Realtime $realtime,
        private readonly Table $stats,
        private readonly EventTailRegistry $eventTailRegistry,
        private readonly Usage $usage,
        private readonly Histogram $arrivalDelay,
        private readonly Histogram $deliveryDelay,
        private readonly Counter $messagesSent,
        private readonly Counter $outboundBytes,
        private readonly Counter $eventsReceived,
        private readonly Counter $eventsDropped,
        private readonly Counter $eventsFailed,
        ObservableGauge $eventStage,
        private readonly UpDownCounter $subscriptions,
        private readonly array $workerAttributes,
        private readonly AppwriteException $unauthorized,
        private readonly string $unauthorizedPayload,
        private readonly float $timeout,
        private readonly int $workerId,
        private readonly string $traceProjectId = '',
    ) {
        $this->stageSince = \microtime(true);
        $eventStage->observe(fn (callable $observe) => $observe(
            $this->stage === 'idle' ? 0.0 : \microtime(true) - $this->stageSince,
            $this->workerAttributes + ['stage' => $this->stage],
        ));
    }

    /**
     * @return array{project: string, event: string, result: string, receivers: int, error: ?\Throwable}
     */
    public function handle(mixed $redis, string $channel, string $payload): array
    {
        $this->eventsReceived->add(1);
        $this->delivered = 0;
        $this->enter('decode');
        $event = \json_decode($payload, true);
        if (!\is_array($event)) {
            $this->eventsDropped->add(1, ['reason' => 'invalid_payload']);
            $this->enter('idle');

            return ['project' => '', 'event' => '', 'result' => 'invalid_payload', 'receivers' => 0, 'error' => null];
        }

        $error = null;
        try {
            $this->enter('arrival');
            $this->recordArrival($event);

            $this->enter('permissions');
            $closing = [];
            if ($event['permissionsChanged'] && isset($event['userId'])) {
                $closing += $this->revalidateUser($event);
            } elseif ($event['permissionsChanged']) {
                $closing += $this->revalidateProject($event);
            }
            $closing += $this->endDeletedTeams($event);

            $this->enter('deliver');
            $closing += $this->deliver($event, $payload);

            // After delivery, so revoked connections see the event that ended their access.
            $this->enter('close');
            foreach (\array_keys($closing) as $connection) {
                $this->server->send([$connection], $this->unauthorizedPayload);
                $this->server->close($connection, $this->unauthorized->getCode());
            }

            $this->enter('tail');
            $this->tail($event);
        } catch (\Throwable $th) {
            $this->eventsFailed->add(1, ['stage' => $this->stage]);
            $error = $th;
        } finally {
            $this->enter('idle');
        }

        return [
            'project' => (string) ($event['project'] ?? ''),
            'event' => (string) ($event['data']['events'][0] ?? ''),
            'result' => match (true) {
                $error !== null => 'failed',
                $this->delivered > 0 => 'delivered',
                default => 'no_subscriber',
            },
            'receivers' => $this->delivered,
            'error' => $error,
        ];
    }

    /**
     * Whether an event's span should be logged: failed, slow, or of the traced project.
     *
     * @param array{project: string, event: string, result: string, receivers: int, error: ?\Throwable} $outcome
     */
    public function shouldLog(array $outcome, float $seconds): bool
    {
        return $outcome['error'] !== null
            || $seconds >= 1
            || ($this->traceProjectId !== '' && $outcome['project'] === $this->traceProjectId);
    }

    private function enter(string $stage): void
    {
        $this->stage = $stage;
        $this->stageSince = \microtime(true);
    }

    private function recordArrival(array $event): void
    {
        $timestamp = $event['data']['timestamp'] ?? null;
        if (!\is_string($timestamp)) {
            return;
        }

        try {
            $this->arrivalDelay->record($this->delayMs($timestamp));
        } catch (\Throwable) {
            $this->eventsFailed->add(1, ['stage' => 'arrival']);
        }
    }

    /**
     * Re-checks one user's connections after their access changed.
     *
     * @return array<int, true> connections to close
     */
    private function revalidateUser(array $event): array
    {
        $projectId = $event['project'];
        $userId = $event['userId'];

        // From connection state, so connections without subscriptions are re-checked too.
        $connections = $this->realtime->getUserConnections($projectId, $userId);
        // Opened while impersonating: they end with this user's session and status.
        $impersonating = $this->realtime->getImpersonatorConnections($projectId, $userId);
        // Admin-mode connections end with this console user's session, status and membership.
        $administering = $projectId === 'console' ? $this->realtime->getAdminConnections($userId) : [];

        if (empty($connections) && empty($impersonating) && empty($administering)) {
            return [];
        }

        // Set on timeout or failure so a late check leaves state alone.
        $abandoned = false;

        return $this->verify(
            [
                'project.id' => $projectId,
                'user.id' => $userId,
                'realtime.event' => $event['data']['events'][0] ?? '',
            ],
            function () use ($projectId, $userId, $connections, $impersonating, $administering, &$abandoned): array {
                return $this->checkUser($projectId, $userId, $connections, $impersonating, $administering, $abandoned);
            },
            function () use ($connections, $impersonating, $administering, &$abandoned): array {
                // Fails closed: a reconnect re-authenticates with fresh roles.
                $abandoned = true;

                $closing = [];
                foreach ([...$connections, ...$impersonating, ...$administering] as $connection) {
                    $closing[$connection] = true;
                }

                return $closing;
            },
        );
    }

    /**
     * Runs in the child coroutine: reads the user and rebuilds or ends each connection.
     *
     * @return array<int, true> connections to close
     */
    private function checkUser(string $projectId, string $userId, array $connections, array $impersonating, array $administering, bool &$abandoned): array
    {
        $realtime = $this->realtime;
        $closing = [];
        $consoleDatabase = \getConsoleDB();
        $project = $consoleDatabase->getAuthorization()->skip(fn () => $consoleDatabase->getDocument('projects', $projectId));
        $database = \getProjectDB($project);

        /** @var User $user */
        $user = $database->getDocument('users', $userId);
        $roles = $user->getRoles($database->getAuthorization());

        // As the HTTP API checks per request; a revoked connection keeps its roles for this one event.
        $revoked = static fn (?string $sessionId): bool => $user->isEmpty()
            || $user->getAttribute('status') === false
            || ($sessionId !== null && (!$user->sessionActive($sessionId, $project->getAttribute('auths', [])['sessionsInvalidatedAt'] ?? null) || !$user->sessionFactorsMet($sessionId)));

        foreach ($impersonating as $connection) {
            if ($abandoned || !isset($realtime->connections[$connection])) {
                continue;
            }

            $impersonatorSessionId = $realtime->connections[$connection]['impersonator']['sessionId'] ?? null;

            // Impersonation also ends once this user is no longer an impersonator.
            if ($revoked($impersonatorSessionId) || $user->getAttribute('impersonator', false) !== true) {
                $closing[$connection] = true;
                continue;
            }

            // The connection runs on this user's session, so it follows that session's expiry.
            if ($impersonatorSessionId !== null) {
                $realtime->connections[$connection]['expire'] = $user->getSessionExpiry($impersonatorSessionId);
            }
        }

        // One query for every administered project, instead of one per connection.
        $administeredIds = [];
        foreach ($administering as $connection) {
            $administeredIds[$realtime->connections[$connection]['projectId'] ?? ''] = true;
        }
        unset($administeredIds['']);
        $administeredProjects = [];
        if (!empty($administeredIds)) {
            $found = $consoleDatabase->getAuthorization()->skip(fn () => $consoleDatabase->find('projects', [
                Query::equal('$id', \array_map(\strval(...), \array_keys($administeredIds))),
                Query::limit(\count($administeredIds)),
            ]));
            foreach ($found as $administered) {
                $administeredProjects[$administered->getId()] = $administered;
            }
        }

        foreach ($administering as $connection) {
            if ($abandoned || !isset($realtime->connections[$connection])) {
                continue;
            }

            $sessionId = $realtime->connections[$connection]['sessionId'] ?? null;
            $admin = $realtime->connections[$connection]['admin'];
            // A deleted project is an empty document here and ends the connection below.
            $administered = $administeredProjects[$realtime->connections[$connection]['projectId'] ?? ''] ?? new Document();

            // Its roles came from this membership, so any change to it ends the connection.
            if (
                $revoked($sessionId)
                || $administered->getAttribute('teamId', '') !== $admin['teamId']
                || $user->getMembershipRoles($admin['teamId']) !== $admin['roles']
            ) {
                $closing[$connection] = true;
                continue;
            }

            if ($sessionId !== null) {
                $realtime->connections[$connection]['expire'] = $user->getSessionExpiry($sessionId);
            }
        }

        foreach ($connections as $connection) {
            if ($abandoned || !isset($realtime->connections[$connection])) {
                continue;
            }

            $sessionId = $realtime->connections[$connection]['sessionId'] ?? null;

            if ($revoked($sessionId)) {
                $closing[$connection] = true;
                continue;
            }

            $this->resubscribe($connection, $projectId, $userId, $roles, $sessionId, $user);
        }

        return $closing;
    }

    /** Rebuilds a connection's subscriptions with the user's new roles, keeping its state. */
    private function resubscribe(int $connection, string $projectId, string $userId, array $roles, ?string $sessionId, User $user): void
    {
        $realtime = $this->realtime;

        $subscriptionsBefore = \count($realtime->getSubscriptionMetadata($connection));
        $authorization = $realtime->connections[$connection]['authorization'] ?? null;
        $impersonatedUserId = $realtime->connections[$connection]['impersonatedUserId'] ?? null;
        $impersonator = $realtime->connections[$connection]['impersonator'] ?? null;
        $presence = $realtime->connections[$connection]['presence'] ?? null;
        $jwtExpire = $realtime->connections[$connection]['jwtExpire'] ?? null;
        $limits = $realtime->connections[$connection]['limits'] ?? null;
        // Re-read, as extending the session moves it; impersonated connections keep theirs.
        $expire = $sessionId !== null
            ? $user->getSessionExpiry($sessionId)
            : ($realtime->connections[$connection]['expire'] ?? null);
        $previousUserId = $realtime->connections[$connection]['userId'] ?? '';

        $meta = $realtime->getSubscriptionMetadata($connection);

        $realtime->unsubscribe($connection);

        foreach ($meta as $subscriptionId => $subscription) {
            $queries = Query::parseQueries($subscription['queries'] ?? []);
            $channels = Realtime::rebindAccountChannels(
                $subscription['channels'] ?? [],
                $previousUserId,
                $userId
            );
            $realtime->subscribe(
                $projectId,
                $connection,
                $subscriptionId,
                $roles,
                $channels,
                $queries,
                $userId
            );
        }

        // A connection with no subscriptions is re-added to its project with the new roles.
        if (!isset($realtime->connections[$connection])) {
            $realtime->subscribe($projectId, $connection, '', $roles, [], [], $userId);
        }
        if ($authorization !== null && isset($realtime->connections[$connection])) {
            $realtime->connections[$connection]['authorization'] = $authorization;
            $realtime->connections[$connection]['impersonatedUserId'] = $impersonatedUserId;
            $realtime->connections[$connection]['impersonator'] = $impersonator;
            $realtime->connections[$connection]['sessionId'] = $sessionId;
            $realtime->connections[$connection]['expire'] = $expire;
            $realtime->connections[$connection]['jwtExpire'] = $jwtExpire;
            // Kept, or closing the socket later cannot release this worker's presence count.
            if ($presence !== null) {
                $realtime->connections[$connection]['presence'] = $presence;
            }
        }
        if ($limits !== null && isset($realtime->connections[$connection])) {
            $realtime->connections[$connection]['limits'] = $limits;
        }

        $subscriptionDelta = \count($realtime->getSubscriptionMetadata($connection)) - $subscriptionsBefore;
        if ($subscriptionDelta !== 0) {
            $this->subscriptions->add($subscriptionDelta, $this->workerAttributes);
        }

        // Tail entries live outside the subscription tree, so they are re-checked separately.
        $this->eventTailRegistry->revalidateConnection($connection, $roles);
    }

    /**
     * Re-checks a project's sessions (`sessions.delete`) and tails (`update.team`), named by its `projects.<id>` channel.
     *
     * @return array<int, true> connections to close
     */
    private function revalidateProject(array $event): array
    {
        $projectId = $this->channelProjectId($event);
        if ($projectId === '' || $projectId === 'console') {
            return [];
        }

        // Set on timeout or failure so a late check leaves state alone.
        $abandoned = false;

        return $this->verify(
            [
                'project.id' => $projectId,
                'realtime.event' => $event['data']['events'][0] ?? '',
            ],
            function () use ($projectId, &$abandoned): array {
                return $this->checkProject($projectId, $abandoned);
            },
            function () use ($projectId, &$abandoned): array {
                // Fails closed: a reconnect re-authenticates with fresh roles.
                $abandoned = true;

                $closing = [];
                foreach ($this->realtime->getProjectConnections($projectId) as $connection) {
                    $userId = $this->realtime->connections[$connection]['userId'] ?? '';
                    $sessionId = $this->realtime->connections[$connection]['sessionId'] ?? null;

                    if (($this->realtime->connections[$connection]['admin'] ?? null) !== null) {
                        $closing[$connection] = true;
                        continue;
                    }

                    // The impersonated connections the sweep checks: those on this project's sessions.
                    if (($this->realtime->connections[$connection]['impersonator']['projectId'] ?? null) === $projectId) {
                        $closing[$connection] = true;
                        continue;
                    }

                    if ($userId === '' || $sessionId === null) {
                        continue; // Guests and keys hold no project session to invalidate.
                    }

                    $closing[$connection] = true;
                }

                return $closing;
            },
        );
    }

    /**
     * Runs in the child coroutine: re-checks every session connection of the project.
     *
     * @return array<int, true> connections to close
     */
    private function checkProject(string $projectId, bool &$abandoned): array
    {
        $realtime = $this->realtime;
        $closing = [];
        $consoleDatabase = \getConsoleDB();
        $project = $consoleDatabase->getAuthorization()->skip(fn () => $consoleDatabase->getDocument('projects', $projectId));
        $database = \getProjectDB($project);
        $invalidatedAt = $project->getAttribute('auths', [])['sessionsInvalidatedAt'] ?? null;
        // Each impersonator is read once per event, however many connections it holds.
        $impersonators = [];

        foreach ($realtime->getProjectConnections($projectId) as $connection) {
            if ($abandoned || !isset($realtime->connections[$connection])) {
                continue;
            }

            $userId = $realtime->connections[$connection]['userId'] ?? '';
            $sessionId = $realtime->connections[$connection]['sessionId'] ?? null;
            $admin = $realtime->connections[$connection]['admin'] ?? null;

            // An admin-mode connection holds no project session, but a transfer ends its access.
            if ($admin !== null) {
                if ($project->getAttribute('teamId', '') !== $admin['teamId']) {
                    $closing[$connection] = true;
                }
                continue;
            }

            // Runs on its impersonator's session, which this sweep ends only for this project's impersonators.
            $impersonator = $realtime->connections[$connection]['impersonator'] ?? null;
            if ($impersonator !== null) {
                if (($impersonator['projectId'] ?? '') !== $projectId) {
                    continue;
                }

                $impersonatorId = (string) ($impersonator['userId'] ?? '');
                $impersonatorSessionId = $impersonator['sessionId'] ?? null;
                /** @var User $user */
                $user = $impersonators[$impersonatorId] ??= $database->getDocument('users', $impersonatorId);

                if (
                    $user->isEmpty()
                    || $user->getAttribute('status') === false
                    || $impersonatorSessionId === null
                    || !$user->sessionActive($impersonatorSessionId, $invalidatedAt)
                    || $user->getAttribute('impersonator', false) !== true
                ) {
                    $closing[$connection] = true;
                }
                continue;
            }

            if ($userId === '' || $sessionId === null) {
                continue; // Guests and keys hold no project session to invalidate.
            }

            /** @var User $user */
            $user = $database->getDocument('users', $userId);

            if (
                $user->isEmpty()
                || $user->getAttribute('status') === false
                || !$user->sessionActive($sessionId, $invalidatedAt)
            ) {
                $closing[$connection] = true;
            }
        }

        if (!$abandoned) {
            $this->eventTailRegistry->reauthorizeProject(
                $projectId,
                $project->getPermissionsByType(PermissionType::Read),
                fn (int $connection): array => $realtime->connections[$connection]['roles'] ?? [],
            );
        }

        return $closing;
    }

    /**
     * Runs $check in a child coroutine, whose fresh context gets fresh database objects, waiting at most $timeout.
     *
     * @return array<int, true> connections to close
     */
    private function verify(array $attributes, callable $check, callable $onFailure): array
    {
        $span = Span::init('realtime.permissions');
        foreach ($attributes as $key => $value) {
            $span->set($key, $value);
        }

        $closing = [];

        try {
            $done = new Channel(1);
            $started = \go(function () use ($check, $done): void {
                try {
                    $done->push(['closing' => $check()]);
                } catch (\Throwable $th) {
                    $done->push(['error' => $th]);
                }
            });

            // Never inline: that brings back the unbounded wait.
            if ($started === false) {
                $span->set('realtime.verification', 'coroutine_limit');
                $span->setError(new AppwriteException(AppwriteException::GENERAL_SERVER_ERROR, 'Permission verification skipped: coroutine limit reached'));

                return $closing = $onFailure();
            }

            $result = $done->pop($this->timeout);
            if ($result === false) {
                $span->set('realtime.verification', 'timed_out');
                $span->setError(new AppwriteException(AppwriteException::DATABASE_TIMEOUT, 'Permission verification timed out after ' . $this->timeout . 's'));

                return $closing = $onFailure();
            }
            if (isset($result['error'])) {
                $span->set('realtime.verification', 'failed');
                $span->setError($result['error']);

                return $closing = $onFailure();
            }

            $span->set('realtime.verification', 'verified');

            return $closing = $result['closing'];
        } finally {
            $span->set('realtime.closed', \count($closing));
            $span->finish();
        }
    }

    /**
     * Deleting an organization names no user, so its admin-mode connections end here.
     *
     * @return array<int, true> connections to close
     */
    private function endDeletedTeams(array $event): array
    {
        $closing = [];
        if (($event['project'] ?? '') !== 'console') {
            return $closing;
        }

        foreach (($event['data']['events'] ?? []) as $name) {
            $parts = \explode('.', $name);
            if (\count($parts) === 3 && $parts[0] === 'teams' && $parts[1] !== '*' && $parts[2] === 'delete') {
                foreach ($this->realtime->getTeamAdminConnections($parts[1]) as $connection) {
                    $closing[$connection] = true;
                }
            }
        }

        return $closing;
    }

    /**
     * Sends the event to its subscribers.
     *
     * @return array<int, true> expired receivers, to close
     */
    private function deliver(array $event, string $payload): array
    {
        $closing = [];
        $receivers = $this->realtime->getSubscribers($event);
        if ($receivers === []) {
            $this->eventsDropped->add(1, ['reason' => 'no_subscriber']);
        }

        if (System::getEnv('_APP_ENV', 'production') === 'development' && !empty($receivers)) {
            Console::log("[Debug][Worker {$this->workerId}] Receivers: " . \count($receivers));
            Console::log("[Debug][Worker {$this->workerId}] Connection IDs: " . \json_encode(\array_keys($receivers)));
            Console::log("[Debug][Worker {$this->workerId}] Matched: " . \json_encode(\array_values($receivers)));
            Console::log("[Debug][Worker {$this->workerId}] Event: " . $payload);
        }

        $total = 0;
        $outboundBytes = 0;

        // Serialized once per event: only the small per-connection `subscriptions` differs.
        $data = $event['data'];
        unset($data['subscriptions']);
        // Array decoding turns `{}` into `[]`, so the payload is re-read as objects when that changed it.
        if ($receivers !== [] && \json_encode($event) !== $payload) {
            $data['payload'] = \json_decode($payload)->data->payload ?? $data['payload'];
        }
        $tail = $data === [] ? '' : ',' . \substr(\json_encode($data), 1, -1);

        $now = \time();
        foreach ($receivers as $id => $matched) {
            // Expiry fires no event, so it is checked on every send.
            if ($this->realtime->isExpired($id, $now)) {
                $closing[$id] = true;
                continue;
            }

            $payloadJson = '{"type":"event","data":{"subscriptions":'
                . \json_encode(\array_keys($matched)) . $tail . '}}';

            $this->server->send([$id], $payloadJson);

            $total++;
            $outboundBytes += \strlen($payloadJson);
        }

        $this->delivered = $total;

        if ($total === 0) {
            return $closing;
        }

        $this->messagesSent->add($total);
        $this->outboundBytes->add($outboundBytes);
        $this->stats->incr($event['project'], 'messages', $total);

        $updatedAt = $event['data']['payload']['$updatedAt'] ?? null;
        if (\is_string($updatedAt)) {
            try {
                $this->deliveryDelay->record($this->delayMs($updatedAt));
            } catch (\Throwable) {
                $this->eventsFailed->add(1, ['stage' => 'delivery_delay']);
            }
        }

        $projectId = $event['project'] ?? null;
        if (!empty($projectId)) {
            $this->usage->add([
                METRIC_REALTIME_CONNECTIONS_MESSAGES_SENT => $total,
                METRIC_REALTIME_OUTBOUND => $outboundBytes,
            ], $projectId);
        }

        return $closing;
    }

    /** Feeds the console live tail, for every event, with or without subscribers. */
    private function tail(array $event): void
    {
        $projectId = $event['project'] ?? '';
        // Project-owned events rerouted to the console name their project in a channel.
        if ($projectId === 'console') {
            $projectId = $this->channelProjectId($event);
        }

        if ($projectId !== '' && $this->eventTailRegistry->isTailed($projectId)) {
            $this->eventTailRegistry->ingest($projectId, Realtime::toTailMetadata($event), \microtime(true));
        }
    }

    /** The project named by the event's first `projects.<id>` channel. */
    private function channelProjectId(array $event): string
    {
        foreach (($event['data']['channels'] ?? []) as $channel) {
            if (\str_starts_with($channel, 'projects.')) {
                return \substr($channel, \strlen('projects.'));
            }
        }

        return '';
    }

    private function delayMs(string $timestamp): int
    {
        $then = new \DateTimeImmutable($timestamp, new \DateTimeZone('UTC'));
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return (int) \max(0, (float) $now->format('U.u') * 1000 - (float) $then->format('U.u') * 1000);
    }
}
