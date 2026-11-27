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
use Utopia\Validator\Text;

class Delete extends Base
{
    public static function getName(): string
    {
        return 'deleteDistributionAppleAssetLibraryPlacement';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath(self::PREFIX . '/v1/appAssetLibraryPlacements/:placementId')
            ->desc('Delete App Store Connect asset library placement')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('placementId', '', new Text(64), 'Placement ID.')
            ->inject('request')
            ->inject('response')
            ->inject('cache')
            ->inject('lock')
            ->callback($this->action(...));
    }

    public function action(string $placementId, Request $request, Response $response, Cache $cache, Lock $lock): void
    {
        $isDevelopment = System::getEnv('_APP_ENV', 'production') === 'development';

        if (!$isDevelopment) {
            throw new Exception(Exception::GENERAL_NOT_IMPLEMENTED);
        }

        $key = $this->authorize($request, $response, $cache);

        if ($key === null) {
            return;
        }

        $localizationId = $cache->load(self::CACHE_IMAGE_PLACEMENT . $placementId, self::TTL);
        $version = \is_string($localizationId) ? $this->versionOfLocalization($cache, $localizationId) : null;

        if (!\is_string($localizationId) || $version === null || !$this->ownsVersion($cache, $key['issuerId'], $version)) {
            $this->notFound($response, self::IMAGE_TYPE_PLACEMENT, $placementId);
            return;
        }

        $imageId = $this->locked($lock, self::CACHE_IMAGE_PLACEMENTS . $localizationId, function () use ($response, $cache, $localizationId, $placementId, $version): ?string {
            $placements = $this->imagePlacements($cache, $localizationId);
            $index = \array_search($placementId, \array_column($placements, 'id'), true);

            if ($index === false) {
                $this->notFound($response, self::IMAGE_TYPE_PLACEMENT, $placementId);
                return null;
            }

            if ($this->imageLocked($response, $version)) {
                return null;
            }

            $imageId = $placements[$index][self::INTERNAL]['imageId'];
            unset($placements[$index]);

            $this->saveImagePlacements($cache, $localizationId, \array_values($placements));

            return $imageId;
        });

        if ($imageId === null) {
            return;
        }

        $this->locked($lock, self::CACHE_IMAGE_USES . $imageId, function () use ($cache, $imageId, $placementId): void {
            $this->saveImageUses($cache, $imageId, \array_values(\array_diff($this->imageUses($cache, $imageId), [$placementId])));
        });

        $cache->purge(self::CACHE_IMAGE_PLACEMENT . $placementId);

        $response->noContent();
    }
}
