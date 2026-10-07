<?php

namespace Appwrite\Deletes;

use Appwrite\Extend\Exception;
use Throwable;
use Utopia\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Limit as LimitException;
use Utopia\Database\Query;

class Targets
{
    /**
     * @param callable(Throwable): void|null $onError Receives a failure instead of it
     *        being thrown. Only a maintenance sweep passes one: it may run against a
     *        database created before `targets` existed and should skip it rather than
     *        fail the run. Deleting a user's or a session's targets passes none, so a
     *        failure there fails the job instead of reporting it done.
     */
    public static function delete(Database $database, Query $query, ?callable $onError = null): void
    {
        $database->deleteDocuments(
            'targets',
            [
                $query,
                Query::orderAsc()
            ],
            Database::DELETE_BATCH_SIZE,
            fn (Document $target) => self::deleteSubscribers($database, $target),
            $onError
        );
    }

    public static function deleteSubscribers(Database $database, Document $target): void
    {
        $database->deleteDocuments(
            'subscribers',
            [
                Query::equal('targetInternalId', [$target->getSequence()]),
                Query::orderAsc(),
            ],
            Database::DELETE_BATCH_SIZE,
            function (Document $subscriber) use ($database, $target) {
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
            }
        );
    }

}
