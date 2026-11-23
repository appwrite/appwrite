<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\Apps\AssetLibrary;

use Appwrite\Extend\Exception;
use Appwrite\Locking\Lock;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\Base;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Cache\Cache;
use Utopia\System\System;
use Utopia\Validator\Text;

class Get extends Base
{
    public static function getName(): string
    {
        return 'getDistributionAppleAssetLibrary';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath(self::PREFIX . '/v1/apps/:appId/assetLibrary')
            ->desc('Get App Store Connect asset library of an app')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('appId', '', new Text(64), 'App ID.')
            ->inject('request')
            ->inject('response')
            ->inject('cache')
            ->inject('lock')
            ->callback($this->action(...));
    }

    public function action(string $appId, Request $request, Response $response, Cache $cache, Lock $lock): void
    {
        $isDevelopment = System::getEnv('_APP_ENV', 'production') === 'development';

        if (!$isDevelopment) {
            throw new Exception(Exception::GENERAL_NOT_IMPLEMENTED);
        }

        $key = $this->authorize($request, $response, $cache);

        if ($key === null) {
            return;
        }

        if (!$this->owns($cache, $key['issuerId'], $appId)) {
            $this->notFound($response, 'apps', $appId);
            return;
        }

        $libraryId = $this->imageLibrary($cache, $lock, $appId);

        $this->respond($response, [
            'data' => [
                'type' => self::IMAGE_TYPE_LIBRARY,
                'id' => $libraryId,
                'links' => ['self' => self::PREFIX . '/v1/appAssetLibraries/' . $libraryId],
            ],
            'links' => ['self' => $this->link($request)],
        ]);
    }
}
