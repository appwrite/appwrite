<?php

namespace Appwrite\AvatarPhotos;

use Utopia\Database\Document;

/**
 * Base class every avatar photo provider extends.
 *
 * A provider knows how to turn a user into raw image bytes from one source —
 * Gravatar, Libravatar, generated initials, a built-in placeholder — and
 * nothing more. Picking which provider actually answers a request is the
 * caller's job; providers never chain into one another.
 */
abstract class Photo
{
    /**
     * Machine-readable name of the provider, e.g. 'gravatar'.
     */
    abstract public function getName(): string;

    /**
     * Whether this provider has everything it needs to attempt a lookup.
     *
     * Providers that key off an email address return false for users without
     * one, so the caller can skip them without paying for a network round-trip.
     */
    abstract public function supports(Document $user): bool;

    /**
     * Fetch the raw image bytes for $user.
     *
     * Returning null means "I have no photo for this user" and is an expected
     * outcome, not an error — the caller moves on to the next provider. Network
     * or rendering failures must be swallowed and reported the same way.
     *
     * @param Document $user   User to resolve a photo for.
     * @param int      $width  Desired width in pixels.
     * @param int      $height Desired height in pixels.
     * @param string   $rating Maximum image rating: 'g' | 'pg' | 'r' | 'x'.
     * @return string|null     Raw image bytes, or null when unavailable.
     */
    abstract public function get(Document $user, int $width, int $height, string $rating): ?string;
}
