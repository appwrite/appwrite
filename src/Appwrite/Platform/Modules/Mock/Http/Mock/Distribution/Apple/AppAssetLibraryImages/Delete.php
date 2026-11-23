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
use Utopia\Validator\Text;

class Delete extends Base
{
    public static function getName(): string
    {
        return 'deleteDistributionAppleAssetLibraryImage';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath(self::PREFIX . '/v1/appAssetLibraryImages/:imageId')
            ->desc('Delete App Store Connect asset library image')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('imageId', '', new Text(64), 'Image ID.')
            ->inject('request')
            ->inject('response')
            ->inject('cache')
            ->inject('lock')
            ->callback($this->action(...));
    }

    public function action(string $imageId, Request $request, Response $response, Cache $cache, Lock $lock): void
    {
        $isDevelopment = System::getEnv('_APP_ENV', 'production') === 'development';

        if (!$isDevelopment) {
            throw new Exception(Exception::GENERAL_NOT_IMPLEMENTED);
        }

        $key = $this->authorize($request, $response, $cache);

        if ($key === null) {
            return;
        }

        $image = $this->image($cache, $imageId);

        if (!$this->ownsImage($cache, $key['issuerId'], $image)) {
            $this->notFound($response, self::IMAGE_TYPE, $imageId);
            return;
        }

        $deleted = $this->locked($lock, self::CACHE_IMAGE_IMAGE . $imageId, fn (): bool => $this->locked($lock, self::CACHE_IMAGE_USES . $imageId, function () use ($cache, $imageId): bool {
            if ($this->imageUses($cache, $imageId) !== []) {
                return false;
            }

            $this->seal($cache, $imageId);
            $cache->purge(self::CACHE_IMAGE_IMAGE . $imageId);
            $cache->purge(self::CACHE_IMAGE_USES . $imageId);

            return true;
        }));

        if (!$deleted) {
            $this->versionConflict($response, self::IMAGE_CODE_HAS_PLACEMENTS, 'The request cannot be fulfilled because of the state of another resource.', "You can't delete an asset that has placements.");
            return;
        }

        $response->noContent();
    }
}
