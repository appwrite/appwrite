<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\AppAssetLibraryPlacements;

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
    private const array ALLOWED_KEYS = [
        'data' => ['type', 'attributes', 'relationships'],
        'attributes' => ['placementType', 'placementGroup'],
        'relationships' => ['image', 'appStoreVersionLocalization'],
    ];

    public static function getName(): string
    {
        return 'createDistributionAppleAssetLibraryPlacement';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath(self::PREFIX . '/v1/appAssetLibraryPlacements')
            ->desc('Create App Store Connect asset library placement')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('data', [], new Assoc(), 'Placement resource with `placementType` and `placementGroup` attributes and `image` and `appStoreVersionLocalization` relationships.')
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

        if (($data['type'] ?? null) !== self::IMAGE_TYPE_PLACEMENT) {
            $this->versionConflict($response, self::CODE_TYPE_INVALID, 'The provided entity type is invalid', "The type must be '" . self::IMAGE_TYPE_PLACEMENT . "'.", '/data/type');
            return;
        }

        $placementType = $attributes['placementType'] ?? null;
        $placementGroup = $attributes['placementGroup'] ?? null;

        if (!\in_array($placementType, self::IMAGE_PLACEMENT_TYPES, true)) {
            $this->versionConflict($response, self::CODE_ATTRIBUTE_INVALID, 'An attribute value is invalid.', "The attribute 'placementType' must be one of " . \implode(', ', self::IMAGE_PLACEMENT_TYPES) . '.', '/data/attributes/placementType');
            return;
        }

        if (!\is_string($placementGroup) || $placementGroup === '') {
            $this->versionConflict($response, self::CODE_ATTRIBUTE_REQUIRED, 'The provided entity is missing a required attribute', "You must provide a value for the attribute 'placementGroup' with this request", '/data/attributes/placementGroup');
            return;
        }

        $imageId = $this->versionLinkage($relationships, 'image', self::IMAGE_TYPE);
        $localizationId = $this->versionLinkage($relationships, 'appStoreVersionLocalization', self::VERSION_TYPE_LOCALIZATION);

        if ($imageId === null || $localizationId === null) {
            $this->versionConflict($response, self::CODE_RELATIONSHIP_REQUIRED, 'The provided entity is missing a required relationship', "You must provide the relationships 'image' and 'appStoreVersionLocalization' with this request", '/data/relationships');
            return;
        }

        $image = $this->image($cache, $imageId);

        if (!$this->ownsImage($cache, $key['issuerId'], $image)) {
            $this->versionConflict($response, self::CODE_RELATIONSHIP_INVALID, 'The provided entity includes a relationship with an invalid value', "The image '{$imageId}' does not exist.", '/data/relationships/image');
            return;
        }

        $version = $this->versionOfLocalization($cache, $localizationId);

        if ($version === null || !$this->ownsVersion($cache, $key['issuerId'], $version) || $version[self::INTERNAL]['appId'] !== $image[self::INTERNAL]['appId']) {
            $this->versionConflict($response, self::CODE_RELATIONSHIP_INVALID, 'The provided entity includes a relationship with an invalid value', "The localization '{$localizationId}' does not exist.", '/data/relationships/appStoreVersionLocalization');
            return;
        }

        if ($this->imageLocked($response, $version)) {
            return;
        }

        if (\in_array($image['attributes']['state'], self::IMAGE_UNPLACEABLE, true) || $image['attributes']['archived']) {
            $this->versionConflict($response, self::VERSION_CODE_STATE, 'The request cannot be fulfilled because of the state of another resource.', "The image '{$imageId}' is {$image['attributes']['state']} and cannot be placed.", '/data/relationships/image');
            return;
        }

        $placement = [
            'type' => self::IMAGE_TYPE_PLACEMENT,
            'id' => $this->uuid(),
            'attributes' => [
                'placementType' => $placementType,
                'placementGroup' => $placementGroup,
                'state' => self::IMAGE_PLACEMENT_STATE,
            ],
            self::INTERNAL => [
                'imageId' => $imageId,
                'localizationId' => $localizationId,
            ],
        ];

        $this->locked($lock, self::CACHE_IMAGE_PLACEMENTS . $localizationId, function () use ($cache, $localizationId, $placement): void {
            $this->saveImagePlacements($cache, $localizationId, [...$this->imagePlacements($cache, $localizationId), $placement]);
        });

        $this->locked($lock, self::CACHE_IMAGE_USES . $imageId, function () use ($cache, $imageId, $placement): void {
            $this->saveImageUses($cache, $imageId, [...$this->imageUses($cache, $imageId), $placement['id']]);
        });

        $this->respond($response, [
            'data' => $this->imagePlacementResource($placement, true),
            'links' => ['self' => $this->link($request)],
        ], Response::STATUS_CODE_CREATED);
    }
}
