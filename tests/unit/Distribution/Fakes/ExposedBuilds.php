<?php

declare(strict_types=1);

namespace Tests\Unit\Distribution\Fakes;

use Appwrite\Distribution\Builds;
use Utopia\Database\Document;

/**
 * Builds with its payloads and storage reachable from tests.
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

    /**
     * @param array<string, string> $variables
     * @return array<string, mixed>
     */
    public function buildPayload(Document $app, Document $target, Document $build, array $variables = [], int $timeout = 3600): array
    {
        $source = ['url' => RecordingGit::ARCHIVE, 'headers' => RecordingGit::HEADERS];

        return static::payload($this->project, $app, $target, $build, ['apiHostname' => 'appwrite'], $timeout, $source, $variables, $this->storage($build, self::STAGE_BUILD));
    }

    /**
     * @param array<string, string> $variables
     * @return array<string, mixed>
     */
    public function assignmentPayload(Document $app, Document $target, Document $build, Document $lease, Document $signingIdentity, array $variables = [], string $token = 'lease-token', int $timeout = 3600): array
    {
        return static::assignment($this->project, $app, $target, $build, $lease, $signingIdentity, $variables, $token, $timeout);
    }
}
