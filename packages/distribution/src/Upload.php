<?php

declare(strict_types=1);

namespace Utopia\Distribution;

use Utopia\Distribution\Exception\Invalid;
use Utopia\Storage\Device;

final readonly class Upload
{
    public const string PLATFORM_IOS = 'ios';

    public const string PLATFORM_ANDROID = 'android';

    private const array PLATFORMS = [self::PLATFORM_IOS, self::PLATFORM_ANDROID];

    public function __construct(
        public Device $device,
        public string $path,
        public string $name,
        public int $size,
        public string $identifier,
        public string $version,
        public string $build,
        public string $platform,
    ) {
        if ($size < 1) {
            throw new Invalid('Upload size must be at least 1 byte');
        }

        if (!\in_array($platform, self::PLATFORMS, true)) {
            throw new Invalid("Upload platform \"{$platform}\" is not supported");
        }

        foreach (['path' => $path, 'name' => $name, 'identifier' => $identifier, 'version' => $version, 'build' => $build] as $field => $value) {
            if ($value === '') {
                throw new Invalid("Upload {$field} must not be empty");
            }
        }
    }
}
