<?php

declare(strict_types=1);

namespace Tests\Unit\Distribution\Fakes;

use Appwrite\Distribution\Schedules;
use Utopia\Database\Document;

final readonly class RecordingSchedules extends Schedules
{
    /**
     * @param \ArrayObject<int, array{string, string, int}> $calls each [operation, resource id, minutes]
     */
    public function __construct(public \ArrayObject $calls = new \ArrayObject())
    {
        parent::__construct(new SubmissionDatabase());
    }

    #[\Override]
    public function write(Document $project, string $type, Document $resource, int $minutes): void
    {
        $this->calls->append(['write', $resource->getId(), $minutes]);
    }

    #[\Override]
    public function stop(Document $project, string $type, string $resourceId): void
    {
        $this->calls->append(['stop', $resourceId, 0]);
    }
}
