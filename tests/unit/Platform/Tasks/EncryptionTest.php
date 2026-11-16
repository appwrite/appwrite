<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Tasks;

use Appwrite\Database\Provisioner;
use Appwrite\OpenSSL\OpenSSL;
use Appwrite\Platform\Tasks\Encryption;
use Appwrite\Storage\Encryption as FileEncryption;
use Generator;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Collection;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\NotFound as NotFoundException;
use Utopia\Database\PermissionType;
use Utopia\Psr7\Stream;
use Utopia\Storage\Cipher;
use Utopia\Storage\Device\Local;

final class EncryptionTest extends TestCase
{
    private const array VARIABLES = ['_APP_OPENSSL_KEY_CURRENT', '_APP_OPENSSL_KEY_V1', '_APP_OPENSSL_KEY_V2'];

    /** @var array<string, string|false> */
    private array $environment = [];

    private string $root;

    private Local $device;

    protected function setUp(): void
    {
        foreach (self::VARIABLES as $name) {
            $this->environment[$name] = \getenv($name);
        }
        \putenv('_APP_OPENSSL_KEY_CURRENT=2');
        \putenv('_APP_OPENSSL_KEY_V1=first-key');
        \putenv('_APP_OPENSSL_KEY_V2=second-key');
        $this->root = \sys_get_temp_dir() . '/encryption-test-' . \bin2hex(\random_bytes(4));
        $this->device = new Local($this->root);
    }

    protected function tearDown(): void
    {
        $this->device->delete($this->root, true);
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

    public function testWholeFileEnvelopesAreReadableWithOnlyTheCurrentKey(): void
    {
        $iv = OpenSSL::randomPseudoBytes(OpenSSL::cipherIVLength(OpenSSL::CIPHER_AES_128_GCM));
        $tag = null;
        $this->device->write($this->root . '/bucket/photo.png', new Stream(OpenSSL::encrypt('pixels', OpenSSL::CIPHER_AES_128_GCM, 'first-key', 0, $iv, $tag)), 'image/png');
        $database = $this->bucket([
            'path' => $this->root . '/bucket/photo.png',
            'openSSLVersion' => '1',
            'openSSLCipher' => OpenSSL::CIPHER_AES_128_GCM,
            'openSSLTag' => \bin2hex($tag ?? ''),
            'openSSLIV' => \bin2hex($iv),
        ]);

        $this->reencrypt($database);

        $this->assertSame('pixels', $this->download($database));
        $this->assertSame('png', \pathinfo($database->stored['bucket_3']['photo']['path'], PATHINFO_EXTENSION));
        $this->assertSame([$database->stored['bucket_3']['photo']['path']], $this->objects());
    }

    public function testSegmentedFilesKeepTheirBytesAndAreReadableWithOnlyTheCurrentKey(): void
    {
        \putenv('_APP_OPENSSL_KEY_CURRENT=1');
        $envelope = FileEncryption::create();
        \putenv('_APP_OPENSSL_KEY_CURRENT=2');
        $this->device->write($this->root . '/bucket/photo.png', new Stream(FileEncryption::cipher($envelope)->encrypt('pixels', 0, true)), 'image/png');
        $database = $this->bucket([
            'path' => $this->root . '/bucket/photo.png',
            'openSSLVersion' => '1',
            'openSSLCipher' => Cipher::NAME,
            'metadata' => ['content_type' => 'image/png', 'encryption' => $envelope],
        ]);

        $this->reencrypt($database);

        $this->assertSame('pixels', $this->download($database));
        $this->assertSame([$this->root . '/bucket/photo.png'], $this->objects());
    }

    public function testAFileDeletedMidRewriteLeavesNoNewObject(): void
    {
        $iv = OpenSSL::randomPseudoBytes(OpenSSL::cipherIVLength(OpenSSL::CIPHER_AES_128_GCM));
        $tag = null;
        $this->device->write($this->root . '/bucket/photo.png', new Stream(OpenSSL::encrypt('pixels', OpenSSL::CIPHER_AES_128_GCM, 'first-key', 0, $iv, $tag)), 'image/png');
        $database = $this->bucket([
            'path' => $this->root . '/bucket/photo.png',
            'openSSLVersion' => '1',
            'openSSLCipher' => OpenSSL::CIPHER_AES_128_GCM,
            'openSSLTag' => \bin2hex($tag ?? ''),
            'openSSLIV' => \bin2hex($iv),
        ]);
        $database->deleted = ['photo'];

        $this->reencrypt($database);

        // The delete endpoint removes the object its document pointed at; nothing the task wrote is left behind
        $this->assertSame([$this->root . '/bucket/photo.png'], $this->objects());
    }

    /**
     * @param array<string, mixed> $file
     */
    private function bucket(array $file): CursorRecordingDatabase
    {
        return new CursorRecordingDatabase(
            rows: [
                'projects' => [new Document(['$id' => 'project', '$sequence' => '1'])],
                'buckets' => [new Document(['$id' => 'bucket', '$sequence' => '3'])],
                'bucket_3' => [new Document(['$id' => 'photo', '$sequence' => '1', 'mimeType' => 'image/png', 'sizeOriginal' => 6, ...$file])],
            ],
            collections: ['projects', 'buckets', 'bucket_3'],
        );
    }

    private function reencrypt(CursorRecordingDatabase $database): void
    {
        // The console and the project share this double, so the file is met twice: the second visit must change nothing
        new Encryption()->action('true', '', 100, 'files', $database, fn () => $database, $this->createStub(Provisioner::class), fn () => $this->device);
        \putenv('_APP_OPENSSL_KEY_V1');
    }

    /**
     * What a download does with the stored document, once the old key is gone.
     */
    private function download(CursorRecordingDatabase $database): string
    {
        $file = new Document($database->stored['bucket_3']['photo']);

        return new FileEncryption($file)->decrypt((string) $this->device->read($file->getAttribute('path')));
    }

    /**
     * @return list<string>
     */
    private function objects(): array
    {
        return \glob($this->root . '/bucket/*') ?: [];
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

    /** @var list<string> Ids deleted by another request once the task has read them */
    public array $deleted = [];

    #[\Override]
    public function updateDocument(string $collection, string $id, Document $document): Document
    {
        foreach ($this->rows[$collection] ?? [] as $row) {
            if ($row->getId() === $id && !\in_array($id, $this->deleted, true)) {
                $this->stored[$collection][$id] = \array_merge($this->stored[$collection][$id] ?? $row->getArrayCopy(), $document->getArrayCopy());

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
