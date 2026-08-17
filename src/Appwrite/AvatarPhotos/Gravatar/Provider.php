<?php

namespace Appwrite\AvatarPhotos\Gravatar;

use Utopia\Fetch\Client;

/**
 * Gravatar provider.
 *
 * Resolves a photo for a given email address via the Gravatar service.
 * Uses the '404' fallback so we get a clear signal when the user has no
 * custom Gravatar; the caller can then move on to the next provider.
 */
class Provider
{
    /**
     * Fetch the raw image bytes for $email, or return null when none exists.
     *
     * @param string $email   Lowercase-trimmed email address to look up.
     * @param string $rating  Maximum rating: 'g' | 'pg' | 'r' | 'x'
     * @return string|null    Raw image bytes, or null when not found / unavailable.
     */
    public function get(string $email, string $rating = 'g'): ?string
    {
        $hash = \hash('sha256', \strtolower(\trim($email)));

        // Use 'd=404' so Gravatar returns HTTP 404 instead of a generic image
        // when the user has no custom avatar — letting us fall through to the
        // next provider.
        $url = 'https://www.gravatar.com/avatar/' . $hash
            . '?s=256&d=404&r=' . \urlencode($rating);

        $client = new Client();
        try {
            $res = $client
                ->setAllowRedirects(true)
                ->fetch($url);
        } catch (\Throwable) {
            return null;
        }

        if ($res->getStatusCode() !== 200) {
            return null;
        }

        $body = $res->getBody();
        if (empty($body)) {
            return null;
        }

        return $body;
    }
}
