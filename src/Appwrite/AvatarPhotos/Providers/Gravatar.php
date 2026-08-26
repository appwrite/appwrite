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

    /**
     * @param string $hash SHA-256 hash of an email address, used instead of
     *                     the user's own email when set. Callers that must not
     *                     handle raw addresses pass the hash straight through.
     */
    public function __construct(
        private readonly string $hash = '',
    ) {
    }

    public function getName(): string
    {
        return 'gravatar';
    }

    public function supports(Document $user): bool
    {
        return !empty($this->hash) || !empty($user->getAttribute('email', ''));
    }

    public function get(Document $user, int $width, int $height, string $rating): ?string
    {
        $hash = !empty($this->hash)
            ? $this->hash
            : \hash('sha256', \strtolower(\trim($user->getAttribute('email', ''))));

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
