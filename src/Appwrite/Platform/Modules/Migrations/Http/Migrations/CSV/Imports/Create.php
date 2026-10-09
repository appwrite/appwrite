<?php

namespace Appwrite\Platform\Modules\Migrations\Http\Migrations\CSV\Imports;

use Appwrite\Event\Event;
use Appwrite\Event\Publisher\Migration as MigrationPublisher;
use Appwrite\Extend\Exception;
use Appwrite\OpenSSL\OpenSSL;
use Appwrite\Platform\Modules\Migrations\Claim;
use Appwrite\Platform\Modules\Migrations\Http\Migrations\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Compression\Algorithms\GZIP;
use Utopia\Compression\Algorithms\Zstd;
use Utopia\Compression\Compression;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Id;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Migration\Destinations\OnDuplicate;
use Utopia\Migration\Resource;
use Utopia\Migration\Sources\Appwrite as AppwriteSource;
use Utopia\Migration\Sources\CSV;
use Utopia\Migration\Transfer;
use Utopia\Platform\Enum;
use Utopia\Psr7\Stream;
use Utopia\Storage\Device;
use Utopia\System\System;
use Utopia\Validator\Boolean;
use Utopia\Validator\WhiteList;

class Create extends Action
{
    public static function getName(): string
    {
        return 'createCSVImport';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/migrations/csv/imports')
            ->httpAlias('/v1/migrations/csv')
            ->desc('Import documents from a CSV')
            ->groups(['api', 'migrations'])
            ->label('scope', 'migrations.write')
            ->label('event', 'migrations.[migrationId].create')
            ->label('audits.event', 'migration.create')
            ->label('sdk', new Method(
                namespace: 'migrations',
                group: null,
                name: 'createCSVImport',
                description: '/docs/references/migrations/migration-csv-import.md',
                auth: [AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_ACCEPTED,
                        model: Response::MODEL_MIGRATION,
                    )
                ]
            ))
            ->param('bucketId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'Storage bucket unique ID. You can create a new storage bucket using the Storage service [server integration](https://appwrite.io/docs/server/storage#createBucket).', false, ['dbForProject'])
            ->param('fileId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'File ID.', false, ['dbForProject'])
            ->param('databaseId', '', new UID(), 'Database ID containing the target collection.')
            ->param('collectionId', '', new UID(), 'Collection ID to import documents into.')
            ->param('internalFile', false, new Boolean(), 'Is the file stored in an internal bucket?', true)
            ->param('onDuplicate', OnDuplicate::Fail->value, new WhiteList(OnDuplicate::values()), 'Behavior when a row with an existing $id is encountered. "fail" (default): abort on first conflict. "skip": silently ignore. "overwrite": replace existing row.', true, enum: new Enum(name: 'OnDuplicate'))
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
            ->inject('locks')
            ->callback($this->action(...));
    }

    public function action(
        string $bucketId,
        string $fileId,
        string $databaseId,
        string $collectionId,
        bool $internalFile,
        string $onDuplicate,
        Response $response,
        Database $dbForProject,
        Database $dbForPlatform,
        Authorization $authorization,
        Document $project,
        array $platform,
        Device $deviceForFiles,
        Device $deviceForMigrations,
        Event $queueForEvents,
        MigrationPublisher $publisherForMigrations,
        callable $locks,
    ): void {
        $claim = new Claim($dbForProject, $locks);
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

        $migrationId = Id::unique();
        $newPath = $deviceForMigrations->getPath($migrationId . '_' . $fileId . '.csv');

        if ($hasEncryption || $hasCompression) {
            $source = (string) $deviceForFiles->read($path);

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
            if (!$deviceForMigrations->write($newPath, new Stream($source), 'text/csv')) {
                throw new \Exception('Unable to copy file');
            }
        } elseif (!$deviceForFiles->copy($path, $newPath, $deviceForMigrations)) {
            throw new \Exception('Unable to copy file');
        }

        $database = $authorization->skip(fn () => $dbForProject->getDocument('databases', $databaseId));
        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $databaseType = $database->getAttribute('type');
        if (!\in_array($databaseType, CSV_ALLOWED_DATABASE_TYPES)) {
            throw new Exception(Exception::MIGRATION_DATABASE_TYPE_UNSUPPORTED, 'Database type not supported for csv');
        }

        $collection = $authorization->skip(fn () => $dbForProject->getDocument('database_' . $database->getSequence(), $collectionId));
        if ($collection->isEmpty()) {
            throw new Exception(Exception::COLLECTION_NOT_FOUND);
        }

        $fileSize = $deviceForMigrations->getFileSize($newPath);
        $resources = Transfer::extractServices([self::transferGroupForDatabaseType($databaseType)]);
        $parentResourceType = self::resourceTypeForDatabaseType($databaseType);

        $migration = $claim->start(
            project: $project,
            migration: new Document([
                '$id' => $migrationId,
                'source' => CSV::getName(),
                'destination' => AppwriteSource::getName(),
                'resources' => $resources,
                'resourceId' => $collection->getId(),
                'resourceInternalId' => $collection->getSequence(),
                'resourceType' => Resource::TYPE_COLLECTION,
                'parentResourceId' => $database->getId(),
                'parentResourceInternalId' => $database->getSequence(),
                'parentResourceType' => $parentResourceType,
                'destinationResourceId' => $database->getId(),
                'destinationResourceInternalId' => $database->getSequence(),
                'destinationResourceType' => $parentResourceType,
                'statusCounters' => '{}',
                'resourceData' => '{}',
                'errors' => [],
                'options' => [
                    'path' => $newPath,
                    'size' => $fileSize,
                    'onDuplicate' => $onDuplicate,
                ],
            ]),
            platform: $platform,
            publisher: $publisherForMigrations,
        );

        $queueForEvents->setParam('migrationId', $migration->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
            ->dynamic($migration, Response::MODEL_MIGRATION);
    }
}
