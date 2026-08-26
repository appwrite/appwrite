<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use Appwrite\Auth\Identity;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Structure;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Validator\Authorization;

final class IdentityTest extends TestCase
{
    /**
     * Mirrors the OAuth2 redirect identity create/update in account.php.
     *
     * CLOUD-3QHA: writing `photo` against a pre-V25 identities collection
     * raises Structure and 500s the whole session. The write path must omit
     * that field until the attribute exists, without dropping token fields.
     */
    public function testOAuthIdentityWriteSucceedsWhenPhotoAttributeIsAbsent(): void
    {
        $database = $this->database();
        $this->createIdentitiesCollection($database, photo: false);

        $photo = 'https://cdn.example/oauth2/photo.jpg';
        $tokens = Identity::withPhoto($database, [
            'providerAccessToken' => 'access-token',
            'providerRefreshToken' => 'refresh-token',
            'providerAccessTokenExpiry' => '2026-08-26T00:00:00.000+00:00',
        ], $photo);

        $this->assertArrayNotHasKey('photo', $tokens);
        $this->assertSame('access-token', $tokens['providerAccessToken']);
        $this->assertSame('refresh-token', $tokens['providerRefreshToken']);
        $this->assertArrayHasKey('providerAccessTokenExpiry', $tokens);

        $created = $database->createDocument('identities', new Document(\array_merge([
            '$id' => ID::unique(),
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::user('user1')),
                Permission::delete(Role::user('user1')),
            ],
            'userInternalId' => '1',
            'userId' => 'user1',
            'provider' => 'google',
            'providerUid' => 'provider-uid',
            'providerEmail' => 'user@example.com',
        ], $tokens)));

        $this->assertFalse($created->isEmpty());
        $this->assertSame('access-token', $created->getAttribute('providerAccessToken'));
        $this->assertSame('refresh-token', $created->getAttribute('providerRefreshToken'));
        $this->assertNull($created->getAttribute('photo'));

        $updated = $database->updateDocument('identities', $created->getId(), new Document(
            Identity::withPhoto($database, [
                'providerAccessToken' => 'rotated-access',
                'providerRefreshToken' => 'rotated-refresh',
                'providerAccessTokenExpiry' => '2026-08-26T00:00:00.000+00:00',
            ], $photo)
        ));

        $this->assertSame('rotated-access', $updated->getAttribute('providerAccessToken'));
        $this->assertSame('rotated-refresh', $updated->getAttribute('providerRefreshToken'));
        $this->assertNull($updated->getAttribute('photo'));

        try {
            $database->createDocument('identities', new Document([
                '$id' => ID::unique(),
                'userInternalId' => '2',
                'userId' => 'user2',
                'provider' => 'google',
                'providerUid' => 'other-uid',
                'photo' => $photo,
            ]));
            $this->fail('Writing photo before V25 must raise Structure.');
        } catch (Structure $exception) {
            $this->assertStringContainsString('photo', $exception->getMessage());
        }
    }

    public function testOAuthIdentityWritePersistsPhotoWhenAttributeExists(): void
    {
        $database = $this->database();
        $this->createIdentitiesCollection($database, photo: true);

        $photo = 'https://cdn.example/oauth2/photo.jpg';
        $created = $database->createDocument('identities', new Document(
            Identity::withPhoto($database, [
                '$id' => ID::unique(),
                'userInternalId' => '1',
                'userId' => 'user1',
                'provider' => 'google',
                'providerUid' => 'provider-uid',
                'providerEmail' => 'user@example.com',
                'providerAccessToken' => 'access-token',
                'providerRefreshToken' => 'refresh-token',
                'providerAccessTokenExpiry' => '2026-08-26T00:00:00.000+00:00',
            ], $photo)
        ));

        $this->assertSame($photo, $created->getAttribute('photo'));

        $updated = $database->updateDocument('identities', $created->getId(), new Document(
            Identity::withPhoto($database, [
                'providerAccessToken' => 'rotated-access',
                'providerRefreshToken' => 'rotated-refresh',
                'providerAccessTokenExpiry' => '2026-08-26T00:00:00.000+00:00',
            ], 'https://cdn.example/oauth2/photo-2.jpg')
        ));

        $this->assertSame('https://cdn.example/oauth2/photo-2.jpg', $updated->getAttribute('photo'));
        $this->assertSame('rotated-access', $updated->getAttribute('providerAccessToken'));
    }

    private function database(): Database
    {
        $authorization = new Authorization();
        $authorization->addRole(Role::any()->toString());

        $database = new Database(new Memory(), new Cache(new NoCache()));
        $database
            ->setAuthorization($authorization)
            ->setDatabase('identityPhoto')
            ->setNamespace('identity_photo_' . \uniqid());
        $database->create();

        return $database;
    }

    private function createIdentitiesCollection(Database $database, bool $photo): void
    {
        $permissions = [
            Permission::create(Role::any()),
            Permission::read(Role::any()),
            Permission::update(Role::any()),
            Permission::delete(Role::any()),
        ];
        $database->createCollection('identities', [], [], $permissions, false);
        $database->createAttribute('identities', 'userInternalId', Database::VAR_STRING, Database::LENGTH_KEY, false);
        $database->createAttribute('identities', 'userId', Database::VAR_STRING, Database::LENGTH_KEY, false);
        $database->createAttribute('identities', 'provider', Database::VAR_STRING, 128, false);
        $database->createAttribute('identities', 'providerUid', Database::VAR_STRING, 2048, false);
        $database->createAttribute('identities', 'providerEmail', Database::VAR_STRING, 320, false);
        $database->createAttribute('identities', 'providerAccessToken', Database::VAR_STRING, 16384, false);
        $database->createAttribute('identities', 'providerRefreshToken', Database::VAR_STRING, 16384, false);
        $database->createAttribute('identities', 'providerAccessTokenExpiry', Database::VAR_STRING, 64, false);

        if ($photo) {
            $database->createAttribute('identities', 'photo', Database::VAR_STRING, 2048, false);
        }
    }
}
