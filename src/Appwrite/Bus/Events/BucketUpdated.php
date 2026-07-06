<?php

namespace Appwrite\Bus\Events;

use Appwrite\Utopia\Response;
use Utopia\Database\Document;

/**
 * Dispatched when a storage bucket is updated (`buckets.[bucketId].update`).
 */
class BucketUpdated extends ResourceEvent
{
    public function __construct(Document $bucket, ?Document $project = null, ?Document $actor = null)
    {
        parent::__construct(
            event: 'buckets.[bucketId].update',
            params: ['bucketId' => $bucket->getId()],
            document: $bucket,
            model: Response::MODEL_BUCKET,
            project: $project,
            user: $actor,
        );
    }
}
