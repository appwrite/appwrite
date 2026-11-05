<?php

namespace Appwrite\Deletes;

use Appwrite\Extend\Exception;
use Appwrite\Messaging\Adapter\Mqtt;
use Utopia\Console\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Limit as LimitException;
use Utopia\Database\Query;

class Targets
{
    public static function delete(Database $database, Query $query, ?Mqtt $mqtt = null, ?string $projectId = null): void
    {
        // Keyed by userId:topicInternalId and collected across every target this query matches
        // (a target+topic pair is unique, but the same user can hold several targets subscribed
        // to the same topic), so a bulk sweep checks what remains in one query per chunk of
        // user/topic pairs, not one per deleted subscriber row.
        $toRevoke = [];

        $database->deleteDocuments(
            'targets',
            [
                $query,
                Query::orderAsc()
            ],
            Database::BATCH_SIZE,
            function (Document $target) use ($database, $mqtt, $projectId, &$toRevoke) {
                self::deleteSubscribers($database, $target, $mqtt, $projectId, $toRevoke);
            }
        );

        self::revokeCollected($database, $mqtt, $projectId, $toRevoke);
    }

    /**
     * @param array<string, array{0: string, 1: string, 2: string}>|null $toRevoke Passed by reference
     * from delete() to collect across its whole batch of targets; left null for a standalone call,
     * which revokes immediately once its own subscriber rows are done.
     */
    public static function deleteSubscribers(Database $database, Document $target, ?Mqtt $mqtt = null, ?string $projectId = null, ?array &$toRevoke = null): void
    {
        $standalone = $toRevoke === null;
        if ($standalone) {
            $toRevoke = [];
        }

        $database->deleteDocuments(
            'subscribers',
            [
                Query::equal('targetInternalId', [$target->getSequence()]),
                Query::orderAsc(),
            ],
            Database::BATCH_SIZE,
            function (Document $subscriber) use ($database, $target, $mqtt, $projectId, &$toRevoke) {
                $topicId = $subscriber->getAttribute('topicId');
                $topicInternalId = $subscriber->getAttribute('topicInternalId');

                $topic = $database->skipFilters(fn () => $database->findOne('topics', [
                    Query::select(['$id', '$sequence']),
                    Query::equal('$sequence', [$topicInternalId]),
                ]), APP_TOPICS_SUBQUERIES);

                if (!$topic->isEmpty()) {
                    $totalAttribute = match ($target->getAttribute('providerType')) {
                        MESSAGE_TYPE_EMAIL => 'emailTotal',
                        MESSAGE_TYPE_SMS => 'smsTotal',
                        MESSAGE_TYPE_PUSH => 'pushTotal',
                        default => throw new Exception('Invalid target provider type'),
                    };

                    try {
                        $database->skipFilters(fn () => $database->decreaseDocumentAttribute(
                            'topics',
                            $topicId,
                            $totalAttribute,
                            min: 0
                        ), APP_TOPICS_SUBQUERIES);
                    } catch (LimitException $e) {
                        Console::error("Delete subscribers decreaseDocumentAttribute (topicId={$topicId}): {$e->getMessage()}");
                    }
                }

                // MQTT delivers only to push targets; an email/SMS row's deletion has nothing
                // for a broker to revoke, so skip the extra bookkeeping this would otherwise cost.
                if ($mqtt !== null && $projectId !== null && $subscriber->getAttribute('providerType') === MESSAGE_TYPE_PUSH) {
                    $userId = $subscriber->getAttribute('userId');
                    $toRevoke[$userId . ':' . $topicInternalId] = [$userId, $topicInternalId, $topicId];
                }
            }
        );

        if ($standalone) {
            self::revokeCollected($database, $mqtt, $projectId, $toRevoke);
        }
    }

    /**
     * Drop each user's live MQTT subscription once they hold no other subscriber row for the topic,
     * matching the subscribe gate (membership is per user, not per target). Mirrors the explicit
     * subscriber-delete endpoint's own notification, which this cleanup path otherwise lacks.
     *
     * @param array<string, array{0: string, 1: string, 2: string}> $toRevoke
     */
    private static function revokeCollected(Database $database, ?Mqtt $mqtt, ?string $projectId, array $toRevoke): void
    {
        if ($mqtt === null || $projectId === null) {
            return;
        }

        foreach (\array_chunk($toRevoke, APP_LIMIT_ARRAY_PARAMS_SIZE, true) as $chunk) {
            $remaining = self::findRemaining($database, $chunk);

            foreach ($chunk as $key => [$userId, , $topicId]) {
                if (isset($remaining[$key])) {
                    continue;
                }

                try {
                    $mqtt->revokeTopic($projectId, $userId, $topicId);
                } catch (\Throwable $error) {
                    Console::error('Failed to unsubscribe MQTT connection: ' . $error->getMessage());
                }
            }
        }
    }

    /**
     * Which of the chunk's user/topic pairs still hold a subscriber row, in one paged query per chunk
     * rather than one lookup per pair. The users × topics filter can match pairs outside the chunk,
     * so rows are keyed the same way as the chunk and only its own keys are read back.
     *
     * @param array<string, array{0: string, 1: string, 2: string}> $chunk
     * @return array<string, true>
     */
    private static function findRemaining(Database $database, array $chunk): array
    {
        $userIds = \array_values(\array_unique(\array_column($chunk, 0)));
        $topicInternalIds = \array_values(\array_unique(\array_column($chunk, 1)));

        $remaining = [];
        $cursor = null;

        do {
            $queries = [
                Query::equal('userId', $userIds),
                Query::equal('topicInternalId', $topicInternalIds),
                Query::select(['$sequence', 'userId', 'topicInternalId']),
                Query::orderAsc('$sequence'),
                Query::limit(Database::BATCH_SIZE),
            ];

            if ($cursor !== null) {
                $queries[] = Query::cursorAfter($cursor);
            }

            $subscribers = $database->skipFilters(fn () => $database->find('subscribers', $queries), APP_TOPICS_SUBQUERIES);

            foreach ($subscribers as $subscriber) {
                $remaining[$subscriber->getAttribute('userId') . ':' . $subscriber->getAttribute('topicInternalId')] = true;
            }

            $cursor = \end($subscribers) ?: null;
        } while (\count($subscribers) === Database::BATCH_SIZE);

        return $remaining;
    }
}
