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

    public function getName(): string
    {
        return 'custom';
    }

    public function supports(Document $profile): bool
    {
        return $profile->getAttribute('avatarPath', '') !== '';
    }

    public function get(Document $profile, int $width, int $height, string $rating): ?string
    {
        $path = $profile->getAttribute('avatarPath', '');

        if ($path === '' || ! $this->deviceForFiles->exists($path)) {
            return null;
        }

        $data = $this->deviceForFiles->read($path);

        return $data === false ? null : (string) $data;
    }
}
