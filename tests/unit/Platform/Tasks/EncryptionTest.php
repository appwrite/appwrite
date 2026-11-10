<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Tasks;

use Appwrite\Database\Provisioner;
use Appwrite\OpenSSL\OpenSSL;
use Appwrite\Platform\Tasks\Encryption;
use Generator;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Collection;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\NotFound as NotFoundException;
use Utopia\Database\PermissionType;
use Utopia\Psr7\Stream;
use Utopia\Storage\Device\Local;

final class EncryptionTest extends TestCase
{
    private const array VARIABLES = ['_APP_OPENSSL_KEY_CURRENT', '_APP_OPENSSL_KEY_V1', '_APP_OPENSSL_KEY_V2'];

    /** @var array<string, string|false> */
    private array $environment = [];

    protected function setUp(): void
    {
        foreach (self::VARIABLES as $name) {
            $this->environment[$name] = \getenv($name);
        }
        \putenv('_APP_OPENSSL_KEY_CURRENT=2');
        \putenv('_APP_OPENSSL_KEY_V1=first-key');
        \putenv('_APP_OPENSSL_KEY_V2=second-key');
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $name => $value) {
            \putenv($value === false ? $name : $name . '=' . $value);
        }
    }

    public function testOnlyDatabasesWithTableMetadataAreWalked(): void
    {
        $database = new CursorRecordingDatabase(
            rows: [
                'projects' => [new Document(['$id' => 'project', '$sequence' => '1'])],
                'databases' => [
                    new Document(['$id' => 'tables', '$sequence' => '7', 'type' => 'tablesdb']),
                    new Document(['$id' => 'postgres', '$sequence' => '8', 'type' => 'postgresql']),
                    new Document(['$id' => 'orphan', '$sequence' => '9', 'type' => 'legacy']),
                ],
            ],
            collections: ['projects', 'databases', 'database_7'],
        );
        $provisioner = $this->createStub(Provisioner::class);
        $provisioner->method('tenant')->willReturn($database);

        // database_8 is a dedicated engine's, kept outside Appwrite's metadata, and database_9's collection is gone:
        // walking either throws `Collection not found` and ends the run before the project is done
        new Encryption()->action('true', '', 100, 'documents', $database, fn () => $database, $provisioner);

        $this->assertSame(['projects', 'databases', 'database_7'], $database->cursors);
    }

    public function testFilesUnderAnOlderKeyAreReadableWithOnlyTheCurrentOne(): void
    {
        $root = \sys_get_temp_dir() . '/encryption-test-' . \bin2hex(\random_bytes(4));
        $device = new Local($root);
        $old = $root . '/bucket/photo.png';
        $iv = OpenSSL::randomPseudoBytes(OpenSSL::cipherIVLength(OpenSSL::CIPHER_AES_128_GCM));
        $tag = null;
        $device->write($old, new Stream(OpenSSL::encrypt('pixels', OpenSSL::CIPHER_AES_128_GCM, 'first-key', 0, $iv, $tag)), 'image/png');

        $file = new Document([
            '$id' => 'photo',
            '$sequence' => '1',
            'path' => $old,
            'mimeType' => 'image/png',
            'openSSLVersion' => '1',
            'openSSLCipher' => OpenSSL::CIPHER_AES_128_GCM,
            'openSSLTag' => \bin2hex($tag ?? ''),
            'openSSLIV' => \bin2hex($iv),
        ]);
        $database = new CursorRecordingDatabase(
            rows: [
                'projects' => [new Document(['$id' => 'project', '$sequence' => '1'])],
                'buckets' => [new Document(['$id' => 'bucket', '$sequence' => '3'])],
                'bucket_3' => [$file],
            ],
            collections: ['projects', 'buckets', 'bucket_3'],
        );

        new Encryption()->action('true', '', 100, 'files', $database, fn () => $database, $this->createStub(Provisioner::class), fn () => $device);

        // What a download does with the stored document, once the old key is gone
        \putenv('_APP_OPENSSL_KEY_V1');
        $stored = $database->stored['bucket_3']['photo'];
        $plain = OpenSSL::decrypt(
            (string) $device->read($stored['path']),
            $stored['openSSLCipher'],
            (string) \getenv('_APP_OPENSSL_KEY_V' . $stored['openSSLVersion']),
            0,
            (string) \hex2bin($stored['openSSLIV']),
            (string) \hex2bin($stored['openSSLTag']),
        );

        $this->assertSame('pixels', $plain);
        $this->assertSame('png', \pathinfo($stored['path'], PATHINFO_EXTENSION));
        $this->assertFalse($device->exists($old));
        $device->delete($root, true);
    }
}

final class CursorRecordingDatabase extends Database
{
    /** @var list<string> */
    public array $cursors = [];

    /**
     * @param array<string, list<Document>> $rows
     * @param list<string> $collections
     */
    public function __construct(private readonly array $rows, private readonly array $collections)
    {
    }

    #[\Override]
    public function findCollection(string $collection): ?Collection
    {
        return \in_array($collection, $this->collections, true) ? Collection::fromArray(['$id' => $collection]) : null;
    }

    /** @var array<string, array<string, array<string, mixed>>> */
    public array $stored = [];

    #[\Override]
    public function updateDocument(string $collection, string $id, Document $document): Document
    {
        foreach ($this->rows[$collection] ?? [] as $row) {
            if ($row->getId() === $id) {
                $this->stored[$collection][$id] = \array_merge($row->getArrayCopy(), $document->getArrayCopy());

                return new Document($this->stored[$collection][$id]);
            }
        }

        return new Document();
    }

    #[\Override]
    public function cursor(string $collection, array $queries = [], int $batchSize = self::CURSOR_BATCH_SIZE, PermissionType $forPermission = PermissionType::Read): Generator
    {
        if (!\in_array($collection, $this->collections, true)) {
            throw new NotFoundException('Collection not found');
        }

        $this->cursors[] = $collection;

        yield from $this->rows[$collection] ?? [];
    }
}
