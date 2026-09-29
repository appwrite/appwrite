<?php

namespace Appwrite\AvatarPhotos\Providers;

use Appwrite\AvatarPhotos\Photo;
use Utopia\Console;
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
        $path = $this->getPath($profile->getId(), $profile->getAttribute('photoId'));

        if (!$this->deviceForFiles->exists($path)) {
            return null;
        }

        return (string) $this->deviceForFiles->read($path);
    }

    /**
     * Bucket IDs can't start with an underscore, so this folder never collides with a bucket's.
     */
    public function getPath(string $userId, string $photoId): string
    {
        return $this->deviceForFiles->getPath('_photos/' . $userId . '-' . $photoId);
    }

    /**
     * Remove the stored file of a photo document. Returns false when the file
     * is still there, so the caller keeps the document and can retry.
     */
    public function delete(Document $photo): bool
    {
        $path = $this->getPath($photo->getAttribute('userId'), $photo->getId());

        if ($photo->getAttribute('chunksUploaded', 0) < $photo->getAttribute('chunksTotal', 1)) {
            try {
                $this->deviceForFiles->abort($path, $photo->getAttribute('metadata', [])['uploadId'] ?? '');
            } catch (\Throwable $th) {
                Console::warning('Failed to abort photo upload ' . $photo->getId() . ': ' . $th->getMessage());
            }

            return true;
        }

        return !$this->deviceForFiles->exists($path) || $this->deviceForFiles->delete($path);
    }
}
