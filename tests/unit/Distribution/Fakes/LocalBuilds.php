<?php

declare(strict_types=1);

namespace Tests\Unit\Distribution\Fakes;

use Utopia\Database\Document;
use Utopia\Storage\Device;
use Utopia\Storage\Device\Local;

/**
 * Builds on a local distribution device rooted in the temporary directory.
 */
final readonly class LocalBuilds extends ExposedBuilds
{
    public static function root(string $projectId): string
    {
        return \sys_get_temp_dir() . "/distribution-builds/app-{$projectId}";
    }

    #[\Override]
    protected static function device(string $projectId): Device
    {
        return new Local(self::root($projectId));
    }

    /**
     * Only a local device downloads the signing bundle through the signing route.
     *
     * @return array<string, mixed>
     */
    public function signingPayload(Document $project, Document $artifact, Document $target, string $token): array
    {
        $platform = ['apiHostname' => 'appwrite'];

        return static::signing($project, $artifact, $target, $platform, $this->storage($artifact, self::STAGE_SIGN), static::route($project, $artifact, $platform, $token));
    }
}
