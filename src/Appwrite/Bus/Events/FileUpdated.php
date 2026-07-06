<?php

namespace Appwrite\Bus\Events;

use Appwrite\Utopia\Response;
use Utopia\Database\Document;

/**
 * Dispatched when a file is updated
 * (`buckets.[bucketId].files.[fileId].update`).
 *
 * Carries the bucket as context, which realtime uses for channel routing.
 */
class FileUpdated extends ResourceEvent
{
    public function __construct(Document $file, Document $bucket, ?Document $project = null, ?Document $actor = null)
    {
        parent::__construct(
            event: 'buckets.[bucketId].files.[fileId].update',
            params: ['bucketId' => $bucket->getId(), 'fileId' => $file->getId()],
            document: $file,
            model: Response::MODEL_FILE,
            project: $project,
            user: $actor,
            context: ['bucket' => $bucket],
        );
    }
}
