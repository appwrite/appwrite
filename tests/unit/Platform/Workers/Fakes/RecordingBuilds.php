<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Workers\Fakes;

use Appwrite\Distribution\Builds;
use Utopia\Database\Document;

final readonly class RecordingBuilds extends Builds
{
    /**
     * @param \ArrayObject<int, string> $canceled
     */
    public function __construct(private \ArrayObject $canceled)
    {
    }

    #[\Override]
    public function cancel(Document $subject): void
    {
        $this->canceled->append($subject->getId());
    }
}
