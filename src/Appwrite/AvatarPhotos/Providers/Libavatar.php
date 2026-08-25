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
        return !empty($user->getAttribute('email', '')) || !empty($user->getAttribute('emailHash', ''));
    }

    public function get(Document $user, int $width, int $height, string $rating): ?string
    {
        // A pre-computed SHA-256 hash (`emailHash`) stands in for the address
        // itself — Libravatar accepts SHA-256 alongside MD5. When we hold the
        // raw email, MD5 stays the default for widest compatibility with
        // older instances.
        $hash = $user->getAttribute('emailHash', '');

        if (empty($hash)) {
            $hash = \md5(\strtolower(\trim($user->getAttribute('email', ''))));
        }

        $url = self::BASE_URL . $hash . '?' . \http_build_query([
            's' => \max($width, $height) > 0 ? \max($width, $height) : 256,
            'd' => '404',
            'r' => $rating,
        ]);

        return $this->fetch($url);
    }
}
