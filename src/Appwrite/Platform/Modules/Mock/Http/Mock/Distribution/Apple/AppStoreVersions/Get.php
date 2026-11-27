<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\AppStoreVersions;

use Appwrite\Extend\Exception;
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
        return 'getDistributionAppleAppStoreVersion';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath(self::PREFIX . '/v1/appStoreVersions/:appStoreVersionId')
            ->desc('Get App Store Connect version')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('appStoreVersionId', '', new Text(64), 'Version ID.')
            ->param('include', '', new Text(256, 0), 'Related resources to include, `build` and `appStoreVersionPhasedRelease`.', true)
            ->inject('request')
            ->inject('response')
            ->inject('cache')
            ->callback($this->action(...));
    }

    public function action(string $appStoreVersionId, string $include, Request $request, Response $response, Cache $cache): void
    {
        $isDevelopment = System::getEnv('_APP_ENV', 'production') === 'development';

        if (!$isDevelopment) {
            throw new Exception(Exception::GENERAL_NOT_IMPLEMENTED);
        }

        $key = $this->authorize($request, $response, $cache);

        if ($key === null) {
            return;
        }

        $version = $this->version($cache, $appStoreVersionId);

        if ($version === null || !$this->ownsVersion($cache, $key['issuerId'], $version)) {
            $this->notFound($response, self::VERSION_TYPE, $appStoreVersionId);
            return;
        }

        $included = $this->versionInclude($include);
        $body = [
            'data' => $this->versionResource($version, $included),
            'links' => ['self' => $this->link($request)],
        ];

        if ($included !== []) {
            $body['included'] = $this->versionIncluded($cache, [$version], $included);
        }

        $this->respond($response, $body);
    }
}
