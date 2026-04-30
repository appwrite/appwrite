<?php

namespace Appwrite\Platform\Modules\Migrations\Http\Migrations\JSON\Imports;

use Appwrite\Event\Event;
use Appwrite\Event\Message\Migration as MigrationMessage;
use Appwrite\Event\Publisher\Migration as MigrationPublisher;
use Appwrite\Extend\Exception;
use Appwrite\OpenSSL\OpenSSL;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\CompoundUID;
use Appwrite\Utopia\Response;
use Utopia\Compression\Algorithms\GZIP;
use Utopia\Compression\Algorithms\Zstd;
use Utopia\Compression\Compression;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Migration\Resource;
use Utopia\Migration\Sources\Appwrite as AppwriteSource;
use Utopia\Migration\Sources\JSON as JSONSource;
use Utopia\Migration\Transfer;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Storage\Device;
use Utopia\System\System;
use Utopia\Validator\Boolean;

class Create extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'createJSONImport';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/migrations/json/imports')
            ->desc('Import documents from a JSON')
            ->groups(['api', 'migrations'])
            ->label('scope', 'migrations.write')
            ->label('event', 'migrations.[migrationId].create')
            ->label('audits.event', 'migration.create')
            ->label('sdk', new Method(
                namespace: 'migrations',
                group: null,
                name: 'createJSONImport',
                description: '/docs/references/migrations/migration-json-import.md',
                auth: [AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_ACCEPTED,
                        model: Response::MODEL_MIGRATION,
                    )
                ]
            ))
            ->param('bucketId', '', new UID(), 'Storage bucket unique ID. You can create a new storage bucket using the Storage service [server integration](https://appwrite.io/docs/server/storage#createBucket).')
            ->param('fileId', '', new UID(), 'File ID.')
            ->param('resourceId', null, new CompoundUID(), 'Composite ID in the format {databaseId:collectionId}, identifying a collection within a database.')
            ->param('internalFile', false, new Boolean(), 'Is the file stored in an internal bucket?', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('dbForPlatform')
            ->inject('authorization')
            ->inject('project')
            ->inject('platform')
            ->inject('deviceForFiles')
            ->inject('deviceForMigrations')
            ->inject('queueForEvents')
            ->inject('publisherForMigrations')
            ->callback($this->action(...));
    }

    public function action(
        string $bucketId,
        string $fileId,
        string $resourceId,
        bool $internalFile,
        Response $response,
        Database $dbForProject,
        Database $dbForPlatform,
        Authorization $authorization,
        Document $project,
        array $platform,
        Device $deviceForFiles,
        Device $deviceForMigrations,
        Event $queueForEvents,
        MigrationPublisher $publisherForMigrations
    ): void {
        $bucket = $authorization->skip(function () use ($internalFile, $dbForPlatform, $dbForProject, $bucketId) {
            if ($internalFile) {
                return $dbForPlatform->getDocument('buckets', 'default');
            }
            return $dbForProject->getDocument('buckets', $bucketId);
        });

        if ($bucket->isEmpty()) {
            throw new Exception(Exception::STORAGE_BUCKET_NOT_FOUND);
        }

        $file = $authorization->skip(fn () => $internalFile ? $dbForPlatform->getDocument('bucket_' . $bucket->getSequence(), $fileId) : $dbForProject->getDocument('bucket_' . $bucket->getSequence(), $fileId));
        if ($file->isEmpty()) {
            throw new Exception(Exception::STORAGE_FILE_NOT_FOUND);
        }

        $path = $file->getAttribute('path', '');
        if (!$deviceForFiles->exists($path)) {
            throw new Exception(Exception::STORAGE_FILE_NOT_FOUND, 'File not found in ' . $path);
        }

        // No encryption or compression on files above 20MB.
        $hasEncryption = !empty($file->getAttribute('openSSLCipher'));
        $compression = $file->getAttribute('algorithm', Compression::NONE);
        $hasCompression = $compression !== Compression::NONE;

        $migrationId = ID::unique();
        $newPath = $deviceForMigrations->getPath($migrationId . '_' . $fileId . '.json');

        if ($hasEncryption || $hasCompression) {
            $source = $deviceForFiles->read($path);

            if ($hasEncryption) {
                $source = OpenSSL::decrypt(
                    $source,
                    $file->getAttribute('openSSLCipher'),
                    System::getEnv('_APP_OPENSSL_KEY_V' . $file->getAttribute('openSSLVersion')),
                    0,
                    hex2bin($file->getAttribute('openSSLIV')),
                    hex2bin($file->getAttribute('openSSLTag'))
                );
            }

            if ($hasCompression) {
                switch ($compression) {
                    case Compression::ZSTD:
                        $source = (new Zstd())->decompress($source);
                        break;
                    case Compression::GZIP:
                        $source = (new GZIP())->decompress($source);
                        break;
                }
            }

            // Manual write after decryption and/or decompression
            if (!$deviceForMigrations->write($newPath, $source, 'application/json')) {
                throw new \Exception('Unable to copy file');
            }
        } elseif (!$deviceForFiles->transfer($path, $newPath, $deviceForMigrations)) {
            throw new \Exception('Unable to copy file');
        }

        $fileSize = $deviceForMigrations->getFileSize($newPath);

        [$databaseId] = \explode(':', $resourceId, 2);
        $database = $authorization->skip(fn () => $dbForProject->getDocument('databases', $databaseId));
        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }
        $databaseType = $database->getAttribute('type');
        $resources = Transfer::extractServices([self::transferGroupForDatabaseType($databaseType)]);
        $resourceType = self::resourceTypeForDatabaseType($databaseType);

        $migration = $dbForProject->createDocument('migrations', new Document([
            '$id' => $migrationId,
            'status' => 'pending',
            'stage' => 'init',
            'source' => JSONSource::getName(),
            'destination' => AppwriteSource::getName(),
            'resources' => $resources,
            'resourceId' => $resourceId,
            'resourceType' => $resourceType,
            'statusCounters' => '{}',
            'resourceData' => '{}',
            'errors' => [],
            'options' => [
                'path' => $newPath,
                'size' => $fileSize,
            ],
        ]));

        $queueForEvents->setParam('migrationId', $migration->getId());

        $publisherForMigrations->enqueue(new MigrationMessage(
            project: $project,
            migration: $migration,
            platform: $platform,
        ));

        $response
            ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
            ->dynamic($migration, Response::MODEL_MIGRATION);
    }

    private static function transferGroupForDatabaseType(string $databaseType): string
    {
        return match ($databaseType) {
            DATABASE_TYPE_LEGACY,
            DATABASE_TYPE_TABLESDB => Transfer::GROUP_DATABASES_TABLES_DB,
            DATABASE_TYPE_VECTORSDB => Transfer::GROUP_DATABASES_VECTOR_DB,
            DATABASE_TYPE_DOCUMENTSDB => Transfer::GROUP_DATABASES_DOCUMENTS_DB,
            default => throw new \LogicException('Unknown database type: ' . $databaseType),
        };
    }

    private static function resourceTypeForDatabaseType(string $databaseType): string
    {
        return match ($databaseType) {
            DATABASE_TYPE_VECTORSDB => Resource::TYPE_DATABASE_VECTORSDB,
            DATABASE_TYPE_DOCUMENTSDB => Resource::TYPE_DATABASE_DOCUMENTSDB,
            default => Resource::TYPE_DATABASE,
        };
    }
}
