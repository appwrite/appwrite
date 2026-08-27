<?php

namespace Appwrite\AvatarPhotos\Providers;

use Appwrite\AvatarPhotos\Photo;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;

/**
 * OAuth2 identity photo provider.
 * Resolves a profile photo from the authenticated user's stored OAuth2 identities
 */
class OAuth2 extends Photo
{
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
     * Identities are queried by user ID
     */
    public function supports(Document $profile): bool
    {
        return $profile->getId() !== '';
    }

    public function get(Document $profile, int $width, int $height, string $rating): ?string
    {
        $identities = $this->dbForProject->find('identities', [
            Query::equal('userId', [$profile->getId()]),
            Query::isNotNull('photo'),
            Query::orderDesc('$updatedAt'),
            Query::limit(self::MAX_ATTEMPTS),
        ]);

        foreach ($identities as $identity) {
            $url = $identity->getAttribute('photo', '');

            if ($url === '') {
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
