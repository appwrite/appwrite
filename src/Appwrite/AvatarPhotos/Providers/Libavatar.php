<?php

namespace Appwrite\AvatarPhotos\Providers;

use Appwrite\AvatarPhotos\Photo;
use Utopia\Database\Document;

/**
 * Libravatar provider.
 *
 * Libravatar is a federated, self-hosted alternative to Gravatar.
 * Resolution order: the domain's SRV record is consulted first
 * (federation); when that is absent we fall back to the central
 * seccdn.libravatar.org service.
 *
 * Libravatar supports d=404 just like Gravatar — we use that for a clean
 * "no photo here" signal.
 */
class Libavatar extends Photo
{
    private const BASE_URL = 'https://seccdn.libravatar.org/avatar/';

    public function getName(): string
    {
        return 'libavatar';
    }

    public function supports(Document $user): bool
    {
        return !empty($user->getAttribute('email', ''));
    }

    public function get(Document $user, int $width, int $height, string $rating): ?string
    {
        $email = $user->getAttribute('email', '');

        // Libravatar accepts both SHA-256 and MD5; use SHA-256 to match Gravatar.
        $hash = \hash('sha256', \strtolower(\trim($email)));

        $url = self::BASE_URL . $hash . '?' . \http_build_query([
            's' => \max($width, $height) > 0 ? \max($width, $height) : 256,
            'd' => '404',
            'r' => $rating,
        ]);

        return $this->fetch($url);
    }
}
