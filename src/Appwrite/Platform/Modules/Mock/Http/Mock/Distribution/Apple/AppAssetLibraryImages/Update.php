<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\AppAssetLibraryImages;

use Appwrite\Extend\Exception;
use Appwrite\Locking\Lock;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\Base;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Cache\Cache;
use Utopia\System\System;
use Utopia\Validator\Assoc;
use Utopia\Validator\Text;

class Update extends Base
{
    private const array ALLOWED_KEYS = [
        'data' => ['type', 'id', 'attributes'],
        'attributes' => ['uploaded', 'referenceName', 'archived'],
    ];

    public static function getName(): string
    {
        return 'updateDistributionAppleAssetLibraryImage';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath(self::PREFIX . '/v1/appAssetLibraryImages/:imageId')
            ->desc('Update App Store Connect asset library image')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('imageId', '', new Text(64), 'Image ID.')
            ->param('data', [], new Assoc(), 'Image resource with optional `uploaded`, `referenceName` and `archived` attributes.')
            ->inject('request')
            ->inject('response')
            ->inject('cache')
            ->inject('lock')
            ->callback($this->action(...));
    }

    /**
     * @param array<string, mixed> $data
     */
    public function action(string $imageId, array $data, Request $request, Response $response, Cache $cache, Lock $lock): void
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

        $image = $this->image($cache, $imageId);

        if (!$this->ownsImage($cache, $key['issuerId'], $image)) {
            $this->notFound($response, self::IMAGE_TYPE, $imageId);
            return;
        }

        if (($data['type'] ?? null) !== self::IMAGE_TYPE || ($data['id'] ?? null) !== $imageId) {
            $this->versionConflict($response, self::CODE_TYPE_INVALID, 'The provided entity type is invalid', 'The type and id must match the image being updated.', '/data');
            return;
        }

        $uploaded = $attributes['uploaded'] ?? false;
        $archived = $attributes['archived'] ?? $image['attributes']['archived'];
        $referenceName = \array_key_exists('referenceName', $attributes) ? $attributes['referenceName'] : $image['attributes']['referenceName'];

        if (!\is_bool($uploaded) || !\is_bool($archived) || ($referenceName !== null && !\is_string($referenceName))) {
            $this->versionConflict($response, self::CODE_ATTRIBUTE_INVALID, 'An attribute value is invalid.', 'The attributes `uploaded` and `archived` must be booleans and `referenceName` a string.', '/data/attributes');
            return;
        }

        $image = $this->locked(
            $lock,
            self::CACHE_IMAGE_IMAGE . $imageId,
            fn (): ?array => $this->commit($response, $cache, $imageId, $uploaded, $attributes),
        );

        if ($image === null) {
            return;
        }

        $this->respond($response, [
            'data' => $this->imageResource($image),
            'links' => ['self' => $this->link($request)],
        ]);
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>|null
     */
    private function commit(Response $response, Cache $cache, string $imageId, bool $uploaded, array $attributes): ?array
    {
        $image = $this->image($cache, $imageId);

        if ($image === null) {
            $this->notFound($response, self::IMAGE_TYPE, $imageId);
            return null;
        }

        $image['attributes']['archived'] = $attributes['archived'] ?? $image['attributes']['archived'];
        $image['attributes']['referenceName'] = \array_key_exists('referenceName', $attributes) ? $attributes['referenceName'] : $image['attributes']['referenceName'];

        if ($uploaded) {
            if ($image['attributes']['state'] !== self::IMAGE_AWAITING_UPLOAD) {
                $this->versionConflict($response, self::VERSION_CODE_STATE, 'The request cannot be fulfilled because of the state of another resource.', "The image '{$imageId}' has already been committed.", '/data/attributes/uploaded');
                return null;
            }

            $reservation = $this->reservation($cache, $imageId);
            $digest = $this->uploaded($cache, $imageId);

            if ($reservation === null || $reservation['expiration'] < \time()) {
                $this->versionConflict($response, self::VERSION_CODE_STATE, 'The request cannot be fulfilled because of the state of another resource.', 'The upload reservation has expired.', '/data/attributes/uploaded');
                return null;
            }

            if ($digest === null) {
                $this->versionConflict($response, self::VERSION_CODE_STATE, 'The request cannot be fulfilled because of the state of another resource.', 'Not every part of the file has been uploaded.', '/data/attributes/uploaded');
                return null;
            }

            $this->seal($cache, $imageId);

            $image['attributes']['uploadOperations'] = null;
            $image[self::INTERNAL]['digest'] = $digest;
            $image = $this->imageSettle($cache, $image);
        }

        $this->saveImage($cache, $image);

        return $image;
    }
}
