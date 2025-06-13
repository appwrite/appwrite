<?php

namespace Appwrite\Deletes;

use Appwrite\Extend\Exception;
use Utopia\Database\Database;
use Utopia\Database\Document;
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
            fn (Document $target) => self::deleteSubscribers($database, $target)
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
                $topic = $database->getDocument('topics', $topicId);
                if (!$topic->isEmpty() && $topic->getSequence() === $topicInternalId) {
                    $totalAttribute = match ($target->getAttribute('providerType')) {
                        MESSAGE_TYPE_EMAIL => 'emailTotal',
                        MESSAGE_TYPE_SMS => 'smsTotal',
                        MESSAGE_TYPE_PUSH => 'pushTotal',
                        default => throw new Exception('Invalid target provider type'),
                    };
                    $database->decreaseDocumentAttribute(
                        'topics',
                        $topicId,
                        $totalAttribute,
                        min: 0
                    );
                }
            }
        );
    }

}
