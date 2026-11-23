<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\BuildUploadFiles;

use Appwrite\Extend\Exception;
use Appwrite\Locking\Lock;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\Base;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Cache\Cache;
use Utopia\Client\Client;
use Utopia\System\System;
use Utopia\Validator\Assoc;
use Utopia\Validator\Text;

class Update extends Base
{
    private const string TYPE = 'buildUploadFiles';
    private const string ALGORITHM = 'SHA_256';
    private const string EVENT_TYPE = 'BUILD_UPLOAD_STATE_UPDATED';
    private const string PROCESSING_STATE = 'VALID';
    private const string AUDIENCE = 'APP_STORE_ELIGIBLE';
    private const array ALLOWED_KEYS = [
        'data' => ['type', 'id', 'attributes'],
        'attributes' => ['uploaded', 'sourceFileChecksums'],
    ];

    public static function getName(): string
    {
        return 'updateDistributionAppleBuildUploadFile';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath(self::PREFIX . '/v1/buildUploadFiles/:buildUploadFileId')
            ->desc('Update App Store Connect build upload file')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('buildUploadFileId', '', new Text(64), 'Build upload file ID.')
            ->param('data', [], new Assoc(), 'Build upload file resource with optional `uploaded` and `sourceFileChecksums` attributes.')
            ->inject('request')
            ->inject('response')
            ->inject('cache')
            ->inject('lock')
            ->inject('clientForWebhooks')
            ->callback($this->action(...));
    }

    /**
     * @param array<string, mixed> $data
     */
    public function action(string $buildUploadFileId, array $data, Request $request, Response $response, Cache $cache, Lock $lock, Client $clientForWebhooks): void
    {
        $isDevelopment = System::getEnv('_APP_ENV', 'production') === 'development';

        if (!$isDevelopment) {
            throw new Exception(Exception::GENERAL_NOT_IMPLEMENTED);
        }

        $key = $this->authorize($request, $response, $cache);

        if ($key === null) {
            return;
        }

        $data = \array_intersect_key($data, \array_flip(self::ALLOWED_KEYS['data']));
        $attributes = \is_array($data['attributes'] ?? null) ? \array_intersect_key($data['attributes'], \array_flip(self::ALLOWED_KEYS['attributes'])) : [];

        if (($data['type'] ?? null) !== self::TYPE) {
            $this->invalid($response, self::CODE_TYPE_INVALID, 'The provided entity type is invalid', "The type must be '" . self::TYPE . "'.", '/data/type');
            return;
        }

        if (($data['id'] ?? null) !== $buildUploadFileId) {
            $this->invalid($response, self::CODE_ATTRIBUTE_INVALID, 'The provided entity id is invalid', "The id must be '{$buildUploadFileId}'.", '/data/id');
            return;
        }

        $file = $cache->load(self::CACHE_UPLOAD_FILES . $buildUploadFileId, self::TTL);

        if (!\is_array($file) || !$this->owns($cache, $key['issuerId'], $file['appId'])) {
            $this->notFound($response, 'buildUploadFiles', $buildUploadFileId);
            return;
        }

        $uploaded = $attributes['uploaded'] ?? false;
        $checksums = $attributes['sourceFileChecksums'] ?? null;
        $hash = \is_array($checksums) && \is_array($checksums['file'] ?? null) ? $checksums['file'] : null;

        if (!\is_bool($uploaded)) {
            $this->invalid($response, self::CODE_ATTRIBUTE_INVALID, 'An attribute value is invalid.', "The attribute 'uploaded' has an invalid value.", '/data/attributes/uploaded');
            return;
        }

        if ($checksums !== null && ($hash === null || ($hash['algorithm'] ?? null) !== self::ALGORITHM || !\is_string($hash['hash'] ?? null))) {
            $this->invalid($response, self::CODE_ATTRIBUTE_INVALID, 'An attribute value is invalid.', "The attribute 'sourceFileChecksums' has an invalid value.", '/data/attributes/sourceFileChecksums');
            return;
        }

        $committed = $this->locked(
            $lock,
            self::CACHE_UPLOAD_BUILDS . $file['buildUploadId'],
            fn (): ?array => $this->commit($response, $cache, $lock, $buildUploadFileId, $uploaded, $hash),
        );

        if ($committed === null) {
            return;
        }

        [$file, $upload] = $committed;

        if ($upload !== null) {
            $this->deliver($cache, $lock, $clientForWebhooks, $upload['appId'], self::EVENT_TYPE, [
                'attributes' => ['newState' => $upload['state']],
                'relationships' => [
                    'instance' => ['data' => ['type' => 'buildUploads', 'id' => $upload['id']]],
                ],
            ]);
        }

        $this->respond($response, [
            'data' => $this->buildUploadFileResource($file),
            'links' => ['self' => $this->link($request)],
        ]);
    }

    /**
     * @param array{hash: string, algorithm: string}|null $hash
     * @return array{0: array<string, mixed>, 1: array<string, mixed>|null}|null
     */
    private function commit(Response $response, Cache $cache, Lock $lock, string $buildUploadFileId, bool $uploaded, ?array $hash): ?array
    {
        $file = $cache->load(self::CACHE_UPLOAD_FILES . $buildUploadFileId, self::TTL);

        if (!\is_array($file)) {
            $this->notFound($response, 'buildUploadFiles', $buildUploadFileId);
            return null;
        }

        if ($file['state'] !== self::UPLOAD_STATE_AWAITING) {
            $this->invalid($response, self::UPLOAD_CODE_STATE, 'The request cannot be fulfilled because of the state of another resource.', "The build upload file '{$buildUploadFileId}' has already been committed.", '/data/attributes/uploaded');
            return null;
        }

        if ($hash !== null) {
            $file['sourceFileChecksums'] = ['file' => ['hash' => $hash['hash'], 'algorithm' => self::ALGORITHM]];
        }

        if ($uploaded) {
            $reservation = $this->reservation($cache, $buildUploadFileId);
            $digest = $this->uploaded($cache, $buildUploadFileId);

            if ($reservation === null || $reservation['expiration'] < \time()) {
                $this->invalid($response, self::UPLOAD_CODE_STATE, 'The request cannot be fulfilled because of the state of another resource.', 'The upload reservation has expired.', '/data/attributes/uploaded');
                return null;
            }

            if ($digest === null) {
                $this->invalid($response, self::UPLOAD_CODE_STATE, 'The request cannot be fulfilled because of the state of another resource.', 'Not every part of the file has been uploaded.', '/data/attributes/uploaded');
                return null;
            }

            if ($hash !== null && \strtolower($hash['hash']) !== $digest) {
                $this->invalid($response, self::CODE_ATTRIBUTE_INVALID, 'An attribute value is invalid.', 'The checksum does not match the uploaded file.', '/data/attributes/sourceFileChecksums');
                return null;
            }

            $this->seal($cache, $buildUploadFileId);

            $file['state'] = self::UPLOAD_STATE_COMPLETE;
            $file['uploadOperations'] = null;
        }

        $cache->save(self::CACHE_UPLOAD_FILES . $buildUploadFileId, $file, '', self::TTL);

        $upload = $uploaded && $file['assetType'] === self::UPLOAD_ASSET ? $this->process($cache, $lock, $file['buildUploadId']) : null;

        return [$file, $upload];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function process(Cache $cache, Lock $lock, string $buildUploadId): ?array
    {
        $upload = $cache->load(self::CACHE_UPLOAD_BUILDS . $buildUploadId, self::TTL);

        if (!\is_array($upload)) {
            return null;
        }

        $seed = $cache->load(self::CACHE_UPLOAD_SEEDS . $upload['appId'] . ':' . $upload['cfBundleVersion'], self::TTL);
        $seed = \is_array($seed) ? $seed : [];
        $now = (new \DateTimeImmutable())->format(\DateTimeInterface::RFC3339_EXTENDED);

        $upload['state'] = $seed['state'] ?? self::UPLOAD_STATE_COMPLETE;
        $upload['errors'] = $seed['errors'] ?? [];
        $upload['uploadedDate'] = $now;

        if ($upload['state'] === self::UPLOAD_STATE_COMPLETE) {
            $build = [
                'type' => 'builds',
                'id' => $this->uuid(),
                'attributes' => [
                    'version' => $upload['cfBundleVersion'],
                    'uploadedDate' => $now,
                    'processingState' => $seed['processingState'] ?? self::PROCESSING_STATE,
                    'usesNonExemptEncryption' => null,
                    'buildAudienceType' => self::AUDIENCE,
                    'expired' => false,
                ],
                self::INTERNAL => [
                    'marketingVersion' => $upload['cfBundleShortVersionString'],
                    'platform' => $upload['platform'],
                    'buildUploadId' => $upload['id'],
                ],
            ];

            $this->saveBuild($cache, $lock, $upload['appId'], $build);
            $upload['buildId'] = $build['id'];
        }

        $cache->save(self::CACHE_UPLOAD_BUILDS . $buildUploadId, $upload, '', self::TTL);

        return $upload;
    }

    private function invalid(Response $response, string $code, string $title, string $detail, string $pointer): void
    {
        $this->error($response, Response::STATUS_CODE_CONFLICT, $code, $title, $detail, ['pointer' => $pointer]);
    }
}
