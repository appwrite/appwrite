<?php

namespace Appwrite\Bus\Events;

use Appwrite\Utopia\Response;
use Utopia\Database\Document;

/**
 * Dispatched when a file is created in a bucket
 * (`buckets.[bucketId].files.[fileId].create`).
 *
 * Carries the bucket as context, which realtime uses for channel routing.
 */
class FileCreated extends ResourceEvent
{
    public function __construct(Document $file, Document $bucket, ?Document $project = null, ?Document $actor = null)
    {
        parent::__construct(
            event: 'buckets.[bucketId].files.[fileId].create',
            params: ['bucketId' => $bucket->getId(), 'fileId' => $file->getId()],
            document: $file,
            model: Response::MODEL_FILE,
            project: $project,
            user: $actor,
            context: ['bucket' => $bucket],
        );
    }
}
