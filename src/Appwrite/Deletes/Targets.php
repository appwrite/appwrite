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
        $database->deleteDocuments(
            'targets',
            [
                $query,
                Query::orderAsc()
            ],
            Database::BATCH_SIZE,
            fn (Document $target) => self::deleteSubscribers($database, $target, $mqtt, $projectId)
        );
    }

    public static function deleteSubscribers(Database $database, Document $target, ?Mqtt $mqtt = null, ?string $projectId = null): void
    {
        $database->deleteDocuments(
            'subscribers',
            [
                Query::equal('targetInternalId', [$target->getSequence()]),
                Query::orderAsc(),
            ],
            Database::BATCH_SIZE,
            function (Document $subscriber) use ($database, $target, $mqtt, $projectId) {
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

                self::revokeIfNoLongerSubscribed($database, $subscriber, $mqtt, $projectId);
            }
        );
    }

    /**
     * Drop the user's live MQTT subscription once they hold no other subscriber row for the topic,
     * matching the subscribe gate (membership is per user, not per target). Mirrors the explicit
     * subscriber-delete endpoint's own notification, which this cleanup path otherwise lacks.
     */
    private static function revokeIfNoLongerSubscribed(Database $database, Document $subscriber, ?Mqtt $mqtt, ?string $projectId): void
    {
        if ($mqtt === null || $projectId === null) {
            return;
        }

        $userId = $subscriber->getAttribute('userId');
        $topicInternalId = $subscriber->getAttribute('topicInternalId');

        $stillSubscribed = $database->skipFilters(fn () => $database->findOne('subscribers', [
            Query::equal('topicInternalId', [$topicInternalId]),
            Query::equal('userId', [$userId]),
        ]), APP_TOPICS_SUBQUERIES);

        if (!$stillSubscribed->isEmpty()) {
            return;
        }

        try {
            $mqtt->revokeTopic($projectId, $userId, $subscriber->getAttribute('topicId'));
        } catch (\Throwable $error) {
            Console::error('Failed to unsubscribe MQTT connection: ' . $error->getMessage());
        }
    }
}
