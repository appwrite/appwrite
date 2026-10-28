<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Databases\Http\Databases\Collections;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Create;
use Appwrite\Utopia\Response;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\Memory as MemoryCache;
use Utopia\Cache\Cache;
use Utopia\Config\Config;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Collection;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Query\Schema\ColumnType;

require_once __DIR__ . '/../../../../../../../../app/init.php';
require_once __DIR__ . '/../../../../../../../../src/Appwrite/Platform/Modules/Databases/Constants.php';

/**
 * Inline attribute definitions on create collection must refuse the encrypt filter the
 * same way the dedicated string/varchar/text endpoints do: gated by the project's plan
 * outside development mode, and by a minimum size for sized string types. Both checks
 * live in the loop just before attribute documents are built, so a deployment whose plan
 * does not include encrypted attributes must never reach attribute creation at all.
 */
final class CreateTest extends TestCase
{
    private Database $database;

    protected function setUp(): void
    {
        $authorization = new Authorization();
        $authorization->disable();

        $this->database = (new Database(new Memory(), new Cache(new MemoryCache())))
            ->setDatabase('appwrite')
            ->setNamespace('_project')
            ->setAuthorization($authorization);

        $this->database->create();

        $collections = Config::getParam('collections', []);
        $this->database->createCollection(Collection::create(
            id: 'databases',
            attributes: $collections['projects']['databases']['attributes'],
            indexes: $collections['projects']['databases']['indexes'],
        ));
    }

    /** @return array{0: Create, 1: Document} [action, database] */
    private function action(): array
    {
        // The constructor only registers SDK method/response-model metadata (unrelated to
        // action()'s own logic) and, doing so, requires ext-swoole; skip it entirely.
        $action = $this->getMockBuilder(Create::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();

        $databaseDocument = $this->database->createDocument('databases', new Document([
            '$id' => 'database-1',
            'name' => 'Database 1',
            'type' => DATABASE_TYPE_LEGACY,
        ]));

        $collections = Config::getParam('collections', []);
        $this->database->createCollection(Collection::create(
            id: 'database_' . $databaseDocument->getSequence(),
            attributes: $collections['databases']['collections']['attributes'],
            indexes: $collections['databases']['collections']['indexes'],
        ));

        return [$action, $databaseDocument];
    }

    /**
     * @param array<string, mixed> $plan
     */
    private function assertRejected(array $plan, string $size): void
    {
        [$action, $databaseDocument] = $this->action();

        try {
            $action->action(
                databaseId: $databaseDocument->getId(),
                collectionId: 'unique()',
                name: 'Collection 1',
                permissions: [],
                documentSecurity: false,
                enabled: true,
                attributes: [[
                    'key' => 'secret',
                    'type' => ColumnType::String->value,
                    'size' => $size,
                    'filters' => ['encrypt'],
                ]],
                indexes: [],
                description: '',
                response: $this->createStub(Response::class),
                dbForProject: $this->database,
                getDatabasesDB: fn () => $this->database,
                queueForEvents: $this->createStub(Event::class),
                authorization: $this->database->getAuthorization(),
                plan: $plan,
            );
            $this->fail('An inline encrypted attribute the plan or size disallows must be refused.');
        } catch (Exception $exception) {
            $this->assertSame(Exception::GENERAL_BAD_REQUEST, $exception->getType());
        }

        // The collection row created before the loop must not be left behind.
        $collectionKey = 'database_' . $databaseDocument->getSequence();
        $this->assertCount(0, $this->database->find($collectionKey, []));
    }

    public function testInlineEncryptedAttributeRejectedWhenThePlanDisallowsEncryption(): void
    {
        $this->assertRejected(['databasesAllowEncrypt' => false], (string) APP_DATABASE_ENCRYPT_SIZE_MIN);
    }

    public function testInlineEncryptedAttributeAcceptedWhenThePlanAllowsEncryption(): void
    {
        [$action, $databaseDocument] = $this->action();

        $action->action(
            databaseId: $databaseDocument->getId(),
            collectionId: 'unique()',
            name: 'Collection 1',
            permissions: [],
            documentSecurity: false,
            enabled: true,
            attributes: [[
                'key' => 'secret',
                'type' => ColumnType::String->value,
                'size' => (string) APP_DATABASE_ENCRYPT_SIZE_MIN,
                'filters' => ['encrypt'],
            ]],
            indexes: [],
            description: '',
            response: $this->createMock(Response::class),
            dbForProject: $this->database,
            getDatabasesDB: fn () => $this->database,
            queueForEvents: $this->createStub(Event::class),
            authorization: $this->database->getAuthorization(),
            plan: ['databasesAllowEncrypt' => true],
        );

        $collectionKey = 'database_' . $databaseDocument->getSequence();
        $this->assertCount(1, $this->database->find($collectionKey, []));
    }

    public function testInlineEncryptedAttributeRejectedWhenSizeIsBelowTheEncryptionMinimum(): void
    {
        $this->assertRejected(['databasesAllowEncrypt' => true], (string) (APP_DATABASE_ENCRYPT_SIZE_MIN - 1));
    }
}
