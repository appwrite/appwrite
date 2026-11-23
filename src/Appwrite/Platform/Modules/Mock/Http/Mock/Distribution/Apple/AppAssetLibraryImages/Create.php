<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\AppAssetLibraryImages;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\Base;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Cache\Cache;
use Utopia\System\System;
use Utopia\Validator\Assoc;

class Create extends Base
{
    private const array ALLOWED_KEYS = [
        'data' => ['type', 'attributes', 'relationships'],
        'attributes' => ['fileName', 'fileSize', 'category', 'referenceName'],
        'relationships' => ['assetLibrary'],
    ];

    public static function getName(): string
    {
        return 'createDistributionAppleAssetLibraryImage';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath(self::PREFIX . '/v1/appAssetLibraryImages')
            ->desc('Create App Store Connect asset library image')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('data', [], new Assoc(), 'Image resource with `fileName`, `fileSize`, `category` and optional `referenceName` attributes and an `assetLibrary` relationship.')
            ->inject('request')
            ->inject('response')
            ->inject('cache')
            ->callback($this->action(...));
    }

    /**
     * @param array<string, mixed> $data
     */
    public function action(array $data, Request $request, Response $response, Cache $cache): void
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

        if (($data['type'] ?? null) !== self::IMAGE_TYPE) {
            $this->versionConflict($response, self::CODE_TYPE_INVALID, 'The provided entity type is invalid', "The type must be '" . self::IMAGE_TYPE . "'.", '/data/type');
            return;
        }

        $fileName = $attributes['fileName'] ?? null;
        $fileSize = $attributes['fileSize'] ?? null;
        $category = $attributes['category'] ?? null;
        $referenceName = $attributes['referenceName'] ?? null;

        foreach (['fileName' => $fileName, 'fileSize' => $fileSize, 'category' => $category] as $name => $value) {
            if ($value === null || $value === '') {
                $this->versionConflict($response, self::CODE_ATTRIBUTE_REQUIRED, 'The provided entity is missing a required attribute', "You must provide a value for the attribute '{$name}' with this request", '/data/attributes/' . $name);
                return;
            }
        }

        if (!\is_string($fileName) || !\is_int($fileSize) || $fileSize < 1) {
            $this->versionConflict($response, self::CODE_ATTRIBUTE_INVALID, 'An attribute value is invalid.', 'The file name must be a string and the file size a positive integer.', '/data/attributes');
            return;
        }

        if (!\in_array($category, self::IMAGE_CATEGORIES, true)) {
            $this->versionConflict($response, self::CODE_ATTRIBUTE_INVALID, 'An attribute value is invalid.', "The attribute 'category' must be one of " . \implode(', ', self::IMAGE_CATEGORIES) . '.', '/data/attributes/category');
            return;
        }

        if ($referenceName !== null && !\is_string($referenceName)) {
            $this->versionConflict($response, self::CODE_ATTRIBUTE_INVALID, 'An attribute value is invalid.', "The attribute 'referenceName' has an invalid value.", '/data/attributes/referenceName');
            return;
        }

        $libraryId = $this->versionLinkage($relationships, 'assetLibrary', self::IMAGE_TYPE_LIBRARY);

        if ($libraryId === null) {
            $this->versionConflict($response, self::CODE_RELATIONSHIP_REQUIRED, 'The provided entity is missing a required relationship', "You must provide a value for the relationship 'assetLibrary' with this request", '/data/relationships/assetLibrary');
            return;
        }

        $appId = $this->imageLibraryApp($cache, $libraryId);

        if ($appId === null || !$this->owns($cache, $key['issuerId'], $appId)) {
            $this->versionConflict($response, self::CODE_RELATIONSHIP_INVALID, 'The provided entity includes a relationship with an invalid value', "The asset library '{$libraryId}' does not exist.", '/data/relationships/assetLibrary');
            return;
        }

        $imageId = $this->uuid();
        $image = [
            'type' => self::IMAGE_TYPE,
            'id' => $imageId,
            'attributes' => [
                'fileName' => $fileName,
                'fileSize' => $fileSize,
                'category' => $category,
                'referenceName' => $referenceName,
                'archived' => false,
                'state' => self::IMAGE_AWAITING_UPLOAD,
                'stateDetails' => [],
                'specId' => null,
                'imageAsset' => null,
                'uploadOperations' => $this->reserve($request, $cache, $imageId, $fileSize),
            ],
            self::INTERNAL => [
                'appId' => $appId,
                'libraryId' => $libraryId,
                'digest' => null,
            ],
        ];

        $this->saveImage($cache, $image);

        $this->respond($response, [
            'data' => $this->imageResource($image),
            'links' => ['self' => $this->link($request)],
        ], Response::STATUS_CODE_CREATED);
    }
}
