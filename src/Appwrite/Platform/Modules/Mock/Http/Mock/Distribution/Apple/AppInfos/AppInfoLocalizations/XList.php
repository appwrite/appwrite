<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\AppInfos\AppInfoLocalizations;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\Base;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Cache\Cache;
use Utopia\System\System;
use Utopia\Validator\Range;
use Utopia\Validator\Text;

class XList extends Base
{
    public static function getName(): string
    {
        return 'listDistributionAppleAppInfoLocalizations';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath(self::PREFIX . '/v1/appInfos/:appInfoId/appInfoLocalizations')
            ->desc('List App Store Connect app information localizations')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('appInfoId', '', new Text(64), 'App information ID.')
            ->param('limit', 50, new Range(1, 200), 'Maximum number of localizations to return.', true)
            ->inject('request')
            ->inject('response')
            ->inject('cache')
            ->callback($this->action(...));
    }

    public function action(string $appInfoId, int $limit, Request $request, Response $response, Cache $cache): void
    {
        $isDevelopment = System::getEnv('_APP_ENV', 'production') === 'development';

        if (!$isDevelopment) {
            throw new Exception(Exception::GENERAL_NOT_IMPLEMENTED);
        }

        $key = $this->authorize($request, $response, $cache);

        if ($key === null) {
            return;
        }

        $info = $this->info($cache, $appInfoId);

        if ($info === null || !$this->owns($cache, $key['issuerId'], $info[self::INTERNAL]['appId'])) {
            $this->notFound($response, self::INFO_TYPE, $appInfoId);
            return;
        }

        $localizations = $info[self::INTERNAL]['localizations'];

        $this->respond($response, [
            'data' => \array_map($this->infoLocalizationResource(...), \array_slice($localizations, 0, $limit)),
            'links' => ['self' => $this->link($request)],
            'meta' => ['paging' => ['total' => \count($localizations), 'limit' => $limit]],
        ]);
    }
}
