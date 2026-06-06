<?php

namespace Appwrite\Realtime\Message\Handlers;

use Appwrite\Extend\Exception;
use Appwrite\Messaging\Adapter\Realtime;
use Appwrite\Realtime\EventTailRegistry;
use Appwrite\Realtime\Message\Dispatcher;
use Appwrite\Realtime\Message\Validators\SubscribePayload as SubscribePayloadValidator;
use Appwrite\Utopia\Database\RuntimeQuery;
use Utopia\Database\Database;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Role;
use Utopia\Platform\Action;
use Utopia\Registry\Registry;
use Utopia\Span\Span;

class Subscribe extends Action
{
    public function __construct()
    {
        $this
            ->desc('Bulk subscribe to realtime channels')
            ->label(Dispatcher::LABEL_MESSAGE_TYPE, 'subscribe')
            ->label(Dispatcher::LABEL_PAYLOAD_SHAPE, Dispatcher::PAYLOAD_SHAPE_LIST)
            ->param('items', null, fn () => new SubscribePayloadValidator(), 'Subscriptions to add')
            ->inject('connectionId')
            ->inject('realtime')
            ->inject('register')
            ->inject('projectId')
            ->inject('eventTailRegistry')
            ->inject('dbForConsole')
            ->callback($this->action(...));
    }

    /**
     * @param array<int, array{channels: array<int, string>, queries?: array<int, string>, subscriptionId?: string}> $items
     * @return array<string, mixed>
     */
    public function action(
        array $items,
        int $connectionId,
        Realtime $realtime,
        Registry $register,
        ?string $projectId,
        EventTailRegistry $eventTailRegistry,
        Database $dbForConsole,
    ): array {
        $roles = $realtime->connections[$connectionId]['roles'] ?? [Role::guests()->toString()];
        $userId = $realtime->connections[$connectionId]['userId'] ?? '';

        $parsedPayloads = [];
        $subscriptionsBefore = \count($realtime->getSubscriptionMetadata($connectionId));

        foreach ($items as $payload) {
            $subscriptionId = \array_key_exists('subscriptionId', $payload)
                ? $payload['subscriptionId']
                : ID::unique();

            $queries = $payload['queries'] ?? [];

            try {
                $convertedQueries = Realtime::convertQueries($queries);
            } catch (QueryException $e) {
                throw new Exception(Exception::REALTIME_MESSAGE_FORMAT_INVALID, 'Invalid query: ' . $e->getMessage());
            }

            $convertedChannels = \array_keys(Realtime::convertChannels($payload['channels'], $userId));

            $parsedPayloads[] = [
                'subscriptionId' => $subscriptionId,
                'channels' => $payload['channels'],
                'convertedChannels' => $convertedChannels,
                'queries' => $convertedQueries,
            ];
        }

        foreach ($parsedPayloads as $parsedPayload) {
            $realtime->subscribe(
                $projectId,
                $connectionId,
                $parsedPayload['subscriptionId'],
                $roles,
                $parsedPayload['convertedChannels'],
                $parsedPayload['queries'],
            );
        }

        // Console live event tail: any `console.tail.<projectId>` channel is authorized by
        // team membership of the target project's owning team, then registered with its
        // compiled filter so the worker's pub/sub loop can filter+sample before delivery.
        $now = \microtime(true);
        foreach ($parsedPayloads as $parsedPayload) {
            foreach ($parsedPayload['convertedChannels'] as $channel) {
                $targetProjectId = EventTailRegistry::projectFromChannel($channel);
                if ($targetProjectId === null) {
                    continue;
                }

                $teamId = $eventTailRegistry->cachedTeam($targetProjectId);
                if ($teamId === null) {
                    $project = $dbForConsole->getAuthorization()->skip(
                        fn () => $dbForConsole->getDocument('projects', $targetProjectId)
                    );
                    $teamId = (string) $project->getAttribute('teamId', '');
                    $eventTailRegistry->cacheTeam($targetProjectId, $teamId);
                }

                if ($teamId === '' || !\in_array(Role::team($teamId)->toString(), $roles, true)) {
                    throw new Exception(
                        Exception::REALTIME_POLICY_VIOLATION,
                        'Not authorized to tail project "' . $targetProjectId . '".'
                    );
                }

                $eventTailRegistry->add(
                    $connectionId,
                    $parsedPayload['subscriptionId'],
                    $targetProjectId,
                    RuntimeQuery::compile($parsedPayload['queries']),
                    $now,
                );
            }
        }

        $subscriptionsAfter = \count($realtime->getSubscriptionMetadata($connectionId));
        $subscriptionDelta = $subscriptionsAfter - $subscriptionsBefore;
        $subscriptionsRequested = \count($parsedPayloads);

        if ($subscriptionDelta !== 0) {
            $register->get('telemetry.workerSubscriptionCounter')
                ->add($subscriptionDelta, $register->get('telemetry.workerAttributes'));
        }

        Span::add('realtime.subscription_delta', $subscriptionDelta);
        Span::add('realtime.subscriptions_requested', $subscriptionsRequested);
        Span::add('realtime.subscribe.subscriptions_count', $subscriptionsRequested);

        return [
            'type' => 'response',
            'data' => [
                'to' => 'subscribe',
                'success' => true,
                'subscriptions' => \array_map(static fn (array $parsed): array => [
                    'subscriptionId' => $parsed['subscriptionId'],
                    'channels' => $parsed['convertedChannels'],
                    'queries' => \array_map(static fn ($q) => $q->toString(), $parsed['queries']),
                ], $parsedPayloads),
            ],
        ];
    }
}
