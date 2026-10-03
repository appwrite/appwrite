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
    public static function delete(Database $database, Query $query): void
    {
        $database->deleteDocuments(
            'targets',
            [
                $query,
                Query::orderAsc()
            ],
            Database::DELETE_BATCH_SIZE,
            fn (Document $target) => self::deleteSubscribers($database, $target),
            // A project database created before `targets` existed never got it, and
            // nothing backfills one. Without a sink here the sweep does not skip that
            // database -- it fails the whole maintenance run on it, every run, and the
            // sweeps queued behind this one never happen. Matches the sinks the other
            // maintenance sweeps in Workers\Deletes already pass.
            onError: function (Throwable $th) use ($database): void {
                Console::warning("Skipped the targets sweep on {$database->getDatabase()}: {$th->getMessage()}");
            }
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
