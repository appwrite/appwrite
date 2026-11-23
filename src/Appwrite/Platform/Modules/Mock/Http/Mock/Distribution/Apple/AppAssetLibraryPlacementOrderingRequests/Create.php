<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\AppAssetLibraryPlacementOrderingRequests;

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
        'attributes' => ['placementGroup'],
        'relationships' => ['orderedPlacements', 'appStoreVersionLocalization'],
    ];

    public static function getName(): string
    {
        return 'createDistributionAppleAssetLibraryPlacementOrderingRequest';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath(self::PREFIX . '/v1/appAssetLibraryPlacementOrderingRequests')
            ->desc('Create App Store Connect asset library placement ordering request')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('data', [], new Assoc(), 'Ordering request with a `placementGroup` attribute, the `orderedPlacements` of that group in display order and the `appStoreVersionLocalization` they belong to.')
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

        if (($data['type'] ?? null) !== self::IMAGE_TYPE_ORDERING) {
            $this->versionConflict($response, self::CODE_TYPE_INVALID, 'The provided entity type is invalid', "The type must be '" . self::IMAGE_TYPE_ORDERING . "'.", '/data/type');
            return;
        }

        $placementGroup = $attributes['placementGroup'] ?? null;

        if (!\is_string($placementGroup) || $placementGroup === '') {
            $this->versionConflict($response, self::CODE_ATTRIBUTE_REQUIRED, 'The provided entity is missing a required attribute', "You must provide a value for the attribute 'placementGroup' with this request", '/data/attributes/placementGroup');
            return;
        }

        $localizationId = $this->versionLinkage($relationships, 'appStoreVersionLocalization', self::VERSION_TYPE_LOCALIZATION);
        $version = $localizationId === null ? null : $this->versionOfLocalization($cache, $localizationId);

        if ($localizationId === null || $version === null || !$this->ownsVersion($cache, $key['issuerId'], $version)) {
            $this->versionConflict($response, self::CODE_RELATIONSHIP_INVALID, 'The provided entity includes a relationship with an invalid value', 'The localization does not exist.', '/data/relationships/appStoreVersionLocalization');
            return;
        }

        if ($this->imageLocked($response, $version)) {
            return;
        }

        $ordered = [];
        $linkages = $relationships['orderedPlacements']['data'] ?? null;

        foreach (\is_array($linkages) ? $linkages : [] as $linkage) {
            if (!\is_array($linkage) || ($linkage['type'] ?? null) !== self::IMAGE_TYPE_PLACEMENT || !\is_string($linkage['id'] ?? null)) {
                $this->versionConflict($response, self::CODE_RELATIONSHIP_INVALID, 'The provided entity includes a relationship with an invalid value', 'Every ordered placement must be an appAssetLibraryPlacements linkage.', '/data/relationships/orderedPlacements');
                return;
            }

            $ordered[] = $linkage['id'];
        }

        $reordered = $this->locked($lock, self::CACHE_IMAGE_PLACEMENTS . $localizationId, function () use ($cache, $localizationId, $placementGroup, $ordered): bool {
            $placements = $this->imagePlacements($cache, $localizationId);
            $group = \array_values(\array_filter($placements, fn (array $placement): bool => $placement['attributes']['placementGroup'] === $placementGroup));
            $members = \array_column($group, 'id');
            $sorted = $ordered;
            \sort($members);
            \sort($sorted);

            if ($ordered === [] || $sorted !== $members) {
                return false;
            }

            $byId = \array_column($group, null, 'id');
            $others = \array_values(\array_filter($placements, fn (array $placement): bool => $placement['attributes']['placementGroup'] !== $placementGroup));

            $this->saveImagePlacements($cache, $localizationId, [...$others, ...\array_map(fn (string $placementId): array => $byId[$placementId], $ordered)]);

            return true;
        });

        if (!$reordered) {
            $this->versionConflict($response, self::CODE_RELATIONSHIP_INVALID, 'The provided entity includes a relationship with an invalid value', "The ordered placements must list every placement of the group '{$placementGroup}' exactly once.", '/data/relationships/orderedPlacements');
            return;
        }

        $orderingId = $this->uuid();

        $this->respond($response, [
            'data' => [
                'type' => self::IMAGE_TYPE_ORDERING,
                'id' => $orderingId,
                'attributes' => ['placementGroup' => $placementGroup],
                'links' => ['self' => self::PREFIX . '/v1/appAssetLibraryPlacementOrderingRequests/' . $orderingId],
            ],
            'links' => ['self' => $this->link($request)],
        ], Response::STATUS_CODE_CREATED);
    }
}
