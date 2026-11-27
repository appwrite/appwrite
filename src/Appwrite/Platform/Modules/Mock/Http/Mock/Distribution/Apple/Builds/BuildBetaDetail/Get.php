<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\Builds\BuildBetaDetail;

use Appwrite\Extend\Exception;
use Appwrite\Locking\Lock;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\Base;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Cache\Cache;
use Utopia\Client\Client;
use Utopia\System\System;
use Utopia\Validator\Text;

class Get extends Base
{
    public static function getName(): string
    {
        return 'getDistributionAppleBuildBetaDetail';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath(self::PREFIX . '/v1/builds/:buildId/buildBetaDetail')
            ->desc('Get App Store Connect build beta detail of a build')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('buildId', '', new Text(64), 'Build ID.')
            ->inject('request')
            ->inject('response')
            ->inject('cache')
            ->inject('lock')
            ->inject('clientForWebhooks')
            ->callback($this->action(...));
    }

    public function action(string $buildId, Request $request, Response $response, Cache $cache, Lock $lock, Client $clientForWebhooks): void
    {
        $isDevelopment = System::getEnv('_APP_ENV', 'production') === 'development';

        if (!$isDevelopment) {
            throw new Exception(Exception::GENERAL_NOT_IMPLEMENTED);
        }

        $key = $this->authorize($request, $response, $cache);

        if ($key === null) {
            return;
        }

        $owned = $this->betaBuild($cache, $key['issuerId'], $buildId);

        if ($owned === null) {
            $this->notFound($response, 'builds', $buildId);
            return;
        }

        $this->respond($response, [
            'data' => $this->betaDetailResource($this->betaDetail($cache, $lock, $clientForWebhooks, $owned['appId'], $owned['build'])),
            'links' => ['self' => $this->link($request)],
        ]);
    }
}
