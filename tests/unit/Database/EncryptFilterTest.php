<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\Memory as MemoryCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Attribute;
use Utopia\Database\Collection;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception as DatabaseException;
use Utopia\Database\Validator\Authorization;

/**
 * The encrypt filter writes with the current key version and records it, and reads by the recorded version, so
 * values written under an older key stay readable while new writes move to the current one.
 */
final class EncryptFilterTest extends TestCase
{
    private const array VARIABLES = ['_APP_OPENSSL_KEY_V1', '_APP_OPENSSL_KEY_V2', '_APP_OPENSSL_KEY_CURRENT', '_APP_OPENSSL_KEY_OLDEST'];

    /**
     * @var array<string, string|false>
     */
    private array $environment = [];

    private Database $database;

    protected function setUp(): void
    {
        require __DIR__ . '/../../../app/init/database/filters.php';

        foreach (self::VARIABLES as $name) {
            $this->environment[$name] = \getenv($name);
            \putenv($name);
        }
        \putenv('_APP_OPENSSL_KEY_V1=first-key');

        $authorization = new Authorization();
        $authorization->disable();

        $this->database = (new Database(new Memory(), new Cache(new MemoryCache())))
            ->setDatabase('appwrite')
            ->setNamespace('encrypt')
            ->setAuthorization($authorization);
        $this->database->create();
        $this->database->createCollection(Collection::create(
            id: 'secrets',
            attributes: [Attribute::string(key: 'value', size: 4096, filters: ['encrypt'])],
        ));
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $name => $value) {
            \putenv($value === false ? $name : $name . '=' . $value);
        }
    }

    public function testWritesWithCurrentVersion(): void
    {
        \putenv('_APP_OPENSSL_KEY_V2=second-key');
        \putenv('_APP_OPENSSL_KEY_CURRENT=2');

        $this->database->createDocument('secrets', new Document(['$id' => 'secret', 'value' => 'plain']));

        $this->assertSame('2', $this->version('secret'));
        $this->assertSame('plain', $this->read('secret'));
    }

    public function testRoundTripsAcrossVersions(): void
    {
        $this->database->createDocument('secrets', new Document(['$id' => 'secret', 'value' => 'plain']));
        $this->assertSame('1', $this->version('secret'));

        \putenv('_APP_OPENSSL_KEY_V2=second-key');
        \putenv('_APP_OPENSSL_KEY_CURRENT=2');
        \putenv('_APP_OPENSSL_KEY_OLDEST=2');

        $this->assertSame('plain', $this->read('secret'), 'A value under the old key decrypts by its recorded version');

        $this->database->updateDocument('secrets', 'secret', new Document(['value' => $this->read('secret')]));
        $this->assertSame('2', $this->version('secret'));

        \putenv('_APP_OPENSSL_KEY_V1');
        $this->assertSame('plain', $this->read('secret'), 'A re-encrypted value no longer needs the old key');
    }

    public function testRefusesToEncryptWithoutCurrentKey(): void
    {
        \putenv('_APP_OPENSSL_KEY_CURRENT=2');

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('_APP_OPENSSL_KEY_V2 is not configured');

        $this->database->createDocument('secrets', new Document(['$id' => 'secret', 'value' => 'plain']));
    }

    private function read(string $id): mixed
    {
        $this->database->purgeCachedDocument('secrets', $id);

        return $this->database->getDocument('secrets', $id)->getAttribute('value');
    }

    private function version(string $id): string
    {
        $this->database->purgeCachedDocument('secrets', $id);
        $raw = $this->database->skipFilters(fn () => $this->database->getDocument('secrets', $id));

        return (string) (\json_decode($raw->getAttribute('value'), true)['version'] ?? '');
    }
}
