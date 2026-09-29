<?php

namespace Appwrite\AvatarPhotos\Providers;

use Appwrite\AvatarPhotos\Photo;
use Utopia\Database\Document;
use Utopia\Storage\Device;

class Custom extends Photo
{
    public function __construct(
        private readonly Device $deviceForFiles,
    ) {}

    public static function getPath(Device $deviceForFiles, string $userId, string $photoId): string
    {
        return $deviceForFiles->getPath('photos/' . $userId . '-' . $photoId);
    }

    public function getName(): string
    {
        return 'custom';
    }

    public function supports(Document $profile): bool
    {
        return $profile->getAttribute('avatar', '') !== '';
    }

    public function get(Document $profile, int $width, int $height, string $rating): ?string
    {
        $path = self::getPath($this->deviceForFiles, $profile->getId(), $profile->getAttribute('avatar'));

        if (! $this->deviceForFiles->exists($path)) {
            return null;
        }

        $data = $this->deviceForFiles->read($path);

        return $data === false ? null : (string) $data;
    }
}
