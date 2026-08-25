<?php

namespace Appwrite\AvatarPhotos\Providers;

use Appwrite\AvatarPhotos\Photo;
use Utopia\Database\Document;

/**
 * Gravatar provider.
 *
 * Resolves a photo for a user's email address via the Gravatar service.
 * Uses the '404' fallback so we get a clear signal when the user has no
 * custom Gravatar; the caller can then move on to the next provider.
 */
class Gravatar extends Photo
{
    private const BASE_URL = 'https://www.gravatar.com/avatar/';

    public function getName(): string
    {
        return 'gravatar';
    }

    public function supports(Document $user): bool
    {
        return !empty($user->getAttribute('email', '')) || !empty($user->getAttribute('emailHash', ''));
    }

    public function get(Document $user, int $width, int $height, string $rating): ?string
    {
        // A pre-computed SHA-256 hash (`emailHash`) stands in for the address
        // itself, so callers that never learn the email can still resolve.
        $hash = $user->getAttribute('emailHash', '');

        if (empty($hash)) {
            $hash = \hash('sha256', \strtolower(\trim($user->getAttribute('email', ''))));
        }

        // Use 'd=404' so Gravatar returns HTTP 404 instead of a generic image
        // when the user has no custom avatar — letting us fall through to the
        // next provider.
        $url = self::BASE_URL . $hash . '?' . \http_build_query([
            's' => \max($width, $height) > 0 ? \max($width, $height) : 256,
            'd' => '404',
            'r' => $rating,
        ]);

        return $this->fetch($url);
    }
}
