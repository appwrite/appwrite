<?php

namespace Appwrite\AvatarPhotos\Providers;

use Appwrite\AvatarPhotos\Photo;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;

/**
 * OAuth2 identity photo provider.
 *
 * Resolves a profile photo from the authenticated user's stored OAuth2
 * identities. At login time each OAuth2 adapter (GitHub, Google, …) stores
 * the provider's photo URL in the `photo` field of the identity document.
 * This provider reads those stored URLs, most-recently-updated first, and
 * proxies the first one that returns a valid image.
 *
 * The provider is intentionally agnostic about which OAuth2 service the URL
 * came from — that complexity belongs in the individual OAuth2 adapters.
 */
class OAuth2 extends Photo
{
    /**
     * Maximum number of identity photo URLs to try before giving up.
     *
     * Keeps the worst-case latency bounded when a user has many OAuth2
     * connections with broken CDN links.
     */
    private const MAX_ATTEMPTS = 10;

    public function __construct(
        private readonly Database $dbForProject,
    ) {
    }

    public function getName(): string
    {
        return 'oauth2';
    }

    /**
     * An OAuth2 photo is available as long as the user has at least one
     * identity. Whether any of those identities actually carries a valid
     * photo is determined at fetch time — we do not know without querying.
     */
    public function supports(Document $user): bool
    {
        return ! $user->isEmpty();
    }

    public function get(Document $user, int $width, int $height, string $rating): ?string
    {
        $identities = $this->dbForProject->find('identities', [
            Query::equal('userId', [$user->getId()]),
            Query::isNotNull('photo'),
            Query::orderDesc('$updatedAt'),
            Query::limit(self::MAX_ATTEMPTS),
        ]);

        foreach ($identities as $identity) {
            $url = $identity->getAttribute('photo', '');

            if (empty($url)) {
                continue;
            }

            $data = $this->fetch($url);

            if ($data !== null) {
                return $data;
            }
        }

        return null;
    }
}
