<?php

namespace Utopia\Migration\Tests\Destinations;

use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\Memory as MemoryCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Memory as MemoryAdapter;
use Utopia\Database\Attribute as UtopiaAttribute;
use Utopia\Database\Collection;
use Utopia\Database\Database as UtopiaDatabase;
use Utopia\Database\Document as UtopiaDocument;
use Utopia\Database\Id;
use Utopia\Migration\Resources\Auth\Policies;
use Utopia\Query\Schema\ColumnType;

final class AppwritePoliciesTest extends TestCase
{
    private const string PROJECT = 'destination-project';

    private UtopiaDatabase $database;

    protected function setUp(): void
    {
        $this->database = new UtopiaDatabase(new MemoryAdapter(), new Cache(new MemoryCache()));
        $this->database
            ->setDatabase('appwrite')
            ->setNamespace('_project');
        $this->database->create();

        $this->database->createCollection(Collection::create(
            id: 'projects',
            attributes: [UtopiaAttribute::fromArray([
                'key' => 'auths',
                'type' => ColumnType::String,
                'size' => 16384,
                'filters' => ['json'],
            ])],
        ));
        $this->database->createCollection(Collection::create(
            id: 'authenticators',
            attributes: [UtopiaAttribute::fromArray([
                'key' => 'type',
                'type' => ColumnType::String,
                'size' => 128,
            ])],
        ));
    }

    public function testNewPoliciesAreWrittenToAuths(): void
    {
        $this->seedProject([]);

        $this->import(new Policies(
            self::PROJECT,
            passwordPwned: ['enabled' => true, 'sessions' => true, 'users' => false],
            passwordRotation: ['enabled' => true, 'maxAge' => 7776000],
            passkeyRpId: 'example.com',
            denyRealtimeGuests: true,
        ));

        $auths = $this->auths();
        $this->assertSame(['enabled' => true, 'sessions' => true, 'users' => false], $auths['passwordPwned']);
        $this->assertSame(['enabled' => true, 'maxAge' => 7776000], $auths['passwordRotation']);
        $this->assertSame('example.com', $auths['passkeyRpId']);
        $this->assertTrue($auths['realtimeGuests']);
    }

    public function testUnreportedPoliciesKeepTheDestinationValues(): void
    {
        $this->seedProject([
            'passwordPwned' => ['enabled' => false, 'sessions' => false, 'users' => true],
            'passwordRotation' => ['enabled' => true, 'maxAge' => 86400],
            'passkeyRpId' => 'destination.example.com',
            'realtimeGuests' => true,
        ]);

        $this->import(new Policies(self::PROJECT));

        $auths = $this->auths();
        $this->assertSame(['enabled' => false, 'sessions' => false, 'users' => true], $auths['passwordPwned']);
        $this->assertSame(['enabled' => true, 'maxAge' => 86400], $auths['passwordRotation']);
        $this->assertSame('destination.example.com', $auths['passkeyRpId']);
        $this->assertTrue($auths['realtimeGuests']);
    }

    public function testPasskeyRpIdChangesWhileNoUserHasAPasskey(): void
    {
        $this->seedProject(['passkeyRpId' => 'destination.example.com']);

        $this->import(new Policies(self::PROJECT, passkeyRpId: 'source.example.com'));

        $this->assertSame('source.example.com', $this->auths()['passkeyRpId']);
    }

    public function testPasskeyRpIdIsKeptWhileUsersHavePasskeys(): void
    {
        $this->seedProject(['passkeyRpId' => 'destination.example.com']);
        $this->database->getAuthorization()->skip(fn () => $this->database->createDocument('authenticators', new UtopiaDocument([
            '$id' => Id::unique(),
            'type' => 'passkey',
        ])));

        $this->import(new Policies(self::PROJECT, passkeyRpId: 'source.example.com'));

        $this->assertSame('destination.example.com', $this->auths()['passkeyRpId']);
    }

    /**
     * @param array<string, mixed> $auths
     */
    private function seedProject(array $auths): void
    {
        $this->database->getAuthorization()->skip(fn () => $this->database->createDocument('projects', new UtopiaDocument([
            '$id' => self::PROJECT,
            'auths' => $auths,
        ])));
    }

    private function import(Policies $policies): void
    {
        $destination = new PoliciesDestination(
            project: self::PROJECT,
            endpoint: 'http://example.test/v1',
            key: 'test-key',
            dbForProject: $this->database,
            getDatabasesDB: fn (UtopiaDocument $document): UtopiaDatabase => $this->database,
            collectionStructure: ['attributes' => [], 'indexes' => []],
            dbForPlatform: $this->database,
            projectInternalId: '1',
        );

        $this->database->getAuthorization()->skip(static fn () => $destination->importPolicies($policies));
    }

    /**
     * @return array<string, mixed>
     */
    private function auths(): array
    {
        $project = $this->database->getAuthorization()->skip(fn () => $this->database->getDocument('projects', self::PROJECT));
        $auths = $project->getAttribute('auths', []);
        $this->assertIsArray($auths);

        return $auths;
    }
}
