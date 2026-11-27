<?php

declare(strict_types=1);

namespace Tests\Unit\Distribution\Fakes;

use Appwrite\Distribution\Builds;
use Utopia\Database\Document;

/**
 * Builds with its inspection payload and storage reachable from tests.
 */
readonly class ExposedBuilds extends Builds
{
    /**
     * @return array<string, mixed>
     */
    public function inspectionPayload(Document $project, Document $artifact): array
    {
        return static::inspection($project, $artifact, ['apiHostname' => 'appwrite'], $this->storage($artifact, self::STAGE_INSPECT));
    }
}
