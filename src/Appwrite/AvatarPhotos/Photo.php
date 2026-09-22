<?php

namespace Appwrite\AvatarPhotos;

use Utopia\Database\Document;
use Utopia\Fetch\Client;

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
     * How long a provider may wait to open a connection to a remote service.
     */
    protected const CONNECT_TIMEOUT = 2 * 1000; // 2 seconds

    /**
     * How long a provider may wait for a complete remote response.
     *
     * Providers are tried one after another, so these budgets stack: a single
     * unreachable service must not be able to hold the request open while we
     * still have other providers — and a local fallback — left to try.
     */
    protected const REQUEST_TIMEOUT = 5 * 1000; // 5 seconds

    /**
     * Colours every generated avatar draws in.
     *
     * The initials square and the static placeholder are the two images
     * Appwrite draws itself, and a user moving between them must not see the
     * avatar change identity — so they share one neutral surface and one
     * figure colour, at 8.19:1 contrast.
     */
    protected const SURFACE = '#4F4F4F';
    protected const FIGURE = '#FFFFFF';

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

    /**
     * GET $url with the provider timeout budget applied.
     *
     * Anything other than a 200 with a non-empty body — including a timeout or
     * a transport error — is reported as null so the caller can move on.
     *
     * @return string|null Raw response body, or null when unavailable.
     */
    protected function fetch(string $url): ?string
    {
        $client = new Client();

        try {
            $response = $client
                ->setAllowRedirects(true)
                ->setConnectTimeout(static::CONNECT_TIMEOUT)
                ->setTimeout(static::REQUEST_TIMEOUT)
                ->fetch($url);
        } catch (\Throwable) {
            return null;
        }

        if ($response->getStatusCode() !== 200) {
            return null;
        }

        $body = $response->getBody();

        return $body === '' ? null : $body;
    }
}
