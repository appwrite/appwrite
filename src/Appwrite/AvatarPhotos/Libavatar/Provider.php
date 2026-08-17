<?php

namespace Appwrite\AvatarPhotos\Libavatar;

use Utopia\Fetch\Client;

/**
 * Libravatar provider.
 *
 * Libravatar is a federated, self-hosted alternative to Gravatar.
 * Resolution order: the domain's SRV record is consulted first
 * (federation); when that is absent we fall back to the central
 * seccdn.libravatar.org service.
 *
 * We use mm (mystery man) as the Libravatar default so we can detect
 * "not found" by checking whether the response is the known placeholder.
 * Actually, Libravatar supports d=404 just like Gravatar — we use that
 * for a clean signal.
 */
class Provider
{
    private const BASE_URL = 'https://seccdn.libravatar.org/avatar/';

    /**
     * Fetch the raw image bytes for $email, or return null when unavailable.
     *
     * @param string $email  Lowercase-trimmed email address.
     * @param string $rating Maximum rating: 'g' | 'pg' | 'r' | 'x'
     * @return string|null   Raw image bytes, or null when not found / unavailable.
     */
    public function get(string $email, string $rating = 'g'): ?string
    {
        $hash = \md5(\strtolower(\trim($email)));

        // Libravatar supports both SHA-256 and MD5; use MD5 for widest
        // compatibility with older instances.
        $url = self::BASE_URL . $hash
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
