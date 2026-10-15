<?php

namespace Appwrite\AvatarPhotos\Providers;

use Appwrite\AvatarPhotos\Photo;
use Utopia\Database\Document;
use Utopia\Storage\Device;

class Custom extends Photo
{
    public function __construct(
        private readonly Device $deviceForFiles,
    ) {
    }

    public function getName(): string
    {
        return 'custom';
    }

    public function supports(Document $profile): bool
    {
        return $profile->getAttribute('photoId', '') !== '';
    }

    public function get(Document $profile, int $width, int $height, string $rating): ?string
    {
        $path = $this->deviceForFiles->getPath(APP_STORAGE_PHOTOS . '/' . $profile->getId() . '/' . $profile->getAttribute('photoId'));

        if (!$this->deviceForFiles->exists($path)) {
            return null;
        }

        return (string) $this->deviceForFiles->read($path);
    }
}
