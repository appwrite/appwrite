<?php

namespace Appwrite\AvatarPhotos\Providers;

use Appwrite\AvatarPhotos\Photo;
use Utopia\Database\Document;

/**
 * Gravatar provider
 */
class Gravatar extends Photo
{
    private const BASE_URL = 'https://www.gravatar.com/avatar/';

    public function getName(): string
    {
        return 'gravatar';
    }

    public function supports(Document $profile): bool
    {
        return $profile->getAttribute('emailHash', '') !== '';
    }

    public function get(Document $profile, int $width, int $height, string $rating): ?string
    {
        $url = self::BASE_URL . $profile->getAttribute('emailHash', '') . '?' . \http_build_query([
            's' => \max($width, $height) > 0 ? \max($width, $height) : 256,
            'd' => '404',
            'r' => $rating,
        ]);

        return $this->fetch($url);
    }
}
