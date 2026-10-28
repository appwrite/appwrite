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
        // to the same topic), so a bulk sweep costs one existence check per topic a user is
        // actually revoked from, not one per deleted subscriber row.
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
     * @param array<string, array{0: string, 1: string, 2: string}> $toRevoke
     */
    private static function revokeCollected(Database $database, ?Mqtt $mqtt, ?string $projectId, array $toRevoke): void
    {
        if ($mqtt === null || $projectId === null) {
            return;
        }

        foreach ($toRevoke as [$userId, $topicInternalId, $topicId]) {
            self::revokeIfNoLongerSubscribed($database, $mqtt, $projectId, $userId, $topicInternalId, $topicId);
        }
    }

    /**
     * Drop the user's live MQTT subscription once they hold no other subscriber row for the topic,
     * matching the subscribe gate (membership is per user, not per target). Mirrors the explicit
     * subscriber-delete endpoint's own notification, which this cleanup path otherwise lacks.
     */
    private static function revokeIfNoLongerSubscribed(Database $database, Mqtt $mqtt, string $projectId, string $userId, string $topicInternalId, string $topicId): void
    {
        $stillSubscribed = $database->skipFilters(fn () => $database->findOne('subscribers', [
            Query::equal('topicInternalId', [$topicInternalId]),
            Query::equal('userId', [$userId]),
        ]), APP_TOPICS_SUBQUERIES);

        if (!$stillSubscribed->isEmpty()) {
            return;
        }

        try {
            $mqtt->revokeTopic($projectId, $userId, $topicId);
        } catch (\Throwable $error) {
            Console::error('Failed to unsubscribe MQTT connection: ' . $error->getMessage());
        }
    }
}
