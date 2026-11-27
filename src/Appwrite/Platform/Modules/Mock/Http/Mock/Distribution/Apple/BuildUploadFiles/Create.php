<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\BuildUploadFiles;

use Appwrite\Extend\Exception;
use Appwrite\Locking\Lock;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\Base;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Cache\Cache;
use Utopia\System\System;
use Utopia\Validator\Assoc;

class Create extends Base
{
    private const string TYPE = 'buildUploadFiles';
    private const array ASSET_TYPES = [self::UPLOAD_ASSET, 'ASSET_DESCRIPTION', 'ASSET_SPI'];
    private const array UTIS = ['com.apple.ipa', 'com.apple.pkg', 'com.apple.binary-property-list', 'com.apple.xml-property-list', 'com.pkware.zip-archive'];
    private const array ALLOWED_KEYS = [
        'data' => ['type', 'attributes', 'relationships'],
        'attributes' => ['assetType', 'fileName', 'fileSize', 'uti'],
        'relationships' => ['buildUpload'],
    ];

    public static function getName(): string
    {
        return 'createDistributionAppleBuildUploadFile';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath(self::PREFIX . '/v1/buildUploadFiles')
            ->desc('Create App Store Connect build upload file')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('data', [], new Assoc(), 'Build upload file resource with `assetType`, `fileName`, `fileSize` and `uti` attributes and a `buildUpload` relationship.')
            ->inject('request')
            ->inject('response')
            ->inject('cache')
            ->inject('lock')
            ->callback($this->action(...));
    }

    /**
     * @param array<string, mixed> $data
     */
    public function action(array $data, Request $request, Response $response, Cache $cache, Lock $lock): void
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
        $relationships = \is_array($data['relationships'] ?? null) ? \array_intersect_key($data['relationships'], \array_flip(self::ALLOWED_KEYS['relationships'])) : [];

        if (($data['type'] ?? null) !== self::TYPE) {
            $this->invalid($response, self::CODE_TYPE_INVALID, 'The provided entity type is invalid', "The type must be '" . self::TYPE . "'.", '/data/type');
            return;
        }

        foreach (self::ALLOWED_KEYS['attributes'] as $name) {
            if (!\array_key_exists($name, $attributes)) {
                $this->invalid($response, self::CODE_ATTRIBUTE_REQUIRED, 'The provided entity is missing a required attribute', "You must provide a value for the attribute '{$name}' with this request", '/data/attributes/' . $name);
                return;
            }
        }

        $invalid = $this->violation($attributes);

        if ($invalid !== null) {
            $this->invalid($response, self::CODE_ATTRIBUTE_INVALID, 'An attribute value is invalid.', "The attribute '{$invalid}' has an invalid value.", '/data/attributes/' . $invalid);
            return;
        }

        $buildUploadId = $relationships['buildUpload']['data']['id'] ?? null;

        if (!\is_string($buildUploadId) || $buildUploadId === '' || ($relationships['buildUpload']['data']['type'] ?? null) !== 'buildUploads') {
            $this->invalid($response, self::CODE_RELATIONSHIP_REQUIRED, 'The provided entity is missing a required relationship', "You must provide a value for the relationship 'buildUpload' with this request", '/data/relationships/buildUpload');
            return;
        }

        $file = $this->locked(
            $lock,
            self::CACHE_UPLOAD_BUILDS . $buildUploadId,
            fn (): ?array => $this->reserveFile($request, $response, $cache, $key['issuerId'], $buildUploadId, $attributes),
        );

        if ($file === null) {
            return;
        }

        $this->respond($response, [
            'data' => $this->buildUploadFileResource($file),
            'links' => ['self' => $this->link($request) . '/' . $file['id']],
        ], Response::STATUS_CODE_CREATED);
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>|null
     */
    private function reserveFile(Request $request, Response $response, Cache $cache, string $issuerId, string $buildUploadId, array $attributes): ?array
    {
        $upload = $cache->load(self::CACHE_UPLOAD_BUILDS . $buildUploadId, self::TTL);

        if (!\is_array($upload) || !$this->owns($cache, $issuerId, $upload['appId'])) {
            $this->invalid($response, self::CODE_RELATIONSHIP_INVALID, 'The provided entity includes a relationship with an invalid value', "The build upload '{$buildUploadId}' does not exist.", '/data/relationships/buildUpload');
            return null;
        }

        $asset = $attributes['assetType'] === self::UPLOAD_ASSET;

        if ($upload['state'] !== self::UPLOAD_STATE_AWAITING || ($asset && $upload['fileId'] !== null)) {
            $this->invalid($response, self::UPLOAD_CODE_STATE, 'The request cannot be fulfilled because of the state of another resource.', "The build upload '{$buildUploadId}' does not accept another {$attributes['assetType']} file.", '/data/relationships/buildUpload');
            return null;
        }

        $file = [
            'id' => $this->uuid(),
            'appId' => $upload['appId'],
            'buildUploadId' => $buildUploadId,
            'assetType' => $attributes['assetType'],
            'assetToken' => 'Upload' . \bin2hex(\random_bytes(8)),
            'fileName' => $attributes['fileName'],
            'fileSize' => $attributes['fileSize'],
            'uti' => $attributes['uti'],
            'state' => self::UPLOAD_STATE_AWAITING,
            'sourceFileChecksums' => null,
            'uploadOperations' => null,
        ];

        $file['uploadOperations'] = $this->reserve($request, $cache, $file['id'], $file['fileSize']);

        $cache->save(self::CACHE_UPLOAD_FILES . $file['id'], $file, '', self::TTL);

        if ($asset) {
            $cache->save(self::CACHE_UPLOAD_BUILDS . $buildUploadId, [...$upload, 'fileId' => $file['id']], '', self::TTL);
        }

        return $file;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function violation(array $attributes): ?string
    {
        if (!\in_array($attributes['assetType'], self::ASSET_TYPES, true)) {
            return 'assetType';
        }

        if (!\is_string($attributes['fileName']) || \trim($attributes['fileName']) === '') {
            return 'fileName';
        }

        if (!\is_int($attributes['fileSize']) || $attributes['fileSize'] < 1) {
            return 'fileSize';
        }

        if (!\in_array($attributes['uti'], self::UTIS, true)) {
            return 'uti';
        }

        return null;
    }

    private function invalid(Response $response, string $code, string $title, string $detail, string $pointer): void
    {
        $this->error($response, Response::STATUS_CODE_CONFLICT, $code, $title, $detail, ['pointer' => $pointer]);
    }
}
