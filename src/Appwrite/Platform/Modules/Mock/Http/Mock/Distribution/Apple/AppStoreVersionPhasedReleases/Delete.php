<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\AppStoreVersionPhasedReleases;

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
        return 'deleteDistributionAppleAppStoreVersionPhasedRelease';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath(self::PREFIX . '/v1/appStoreVersionPhasedReleases/:phasedReleaseId')
            ->desc('Delete App Store Connect phased release')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('phasedReleaseId', '', new Text(64), 'Phased release ID.')
            ->inject('request')
            ->inject('response')
            ->inject('cache')
            ->inject('lock')
            ->callback($this->action(...));
    }

    public function action(string $phasedReleaseId, Request $request, Response $response, Cache $cache, Lock $lock): void
    {
        $isDevelopment = System::getEnv('_APP_ENV', 'production') === 'development';

        if (!$isDevelopment) {
            throw new Exception(Exception::GENERAL_NOT_IMPLEMENTED);
        }

        $key = $this->authorize($request, $response, $cache);

        if ($key === null) {
            return;
        }

        $version = $this->versionOfPhasedRelease($cache, $phasedReleaseId);

        if ($version === null || !$this->ownsVersion($cache, $key['issuerId'], $version)) {
            $this->notFound($response, self::VERSION_TYPE_PHASED, $phasedReleaseId);
            return;
        }

        if ($version[self::INTERNAL]['phased']['attributes']['phasedReleaseState'] !== self::VERSION_PHASED_INACTIVE) {
            $this->versionConflict($response, self::VERSION_CODE_STATE, 'The request cannot be fulfilled because of the state of another resource.', 'A phased release can only be deleted before it starts.');
            return;
        }

        $version[self::INTERNAL]['phased'] = null;

        $this->saveVersion($cache, $lock, $version);
        $cache->purge(self::CACHE_VERSION_PHASED . $phasedReleaseId);

        $response->noContent();
    }
}
