<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\Apps\Webhooks;

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
        return 'listDistributionAppleWebhooks';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath(self::PREFIX . '/v1/apps/:appId/webhooks')
            ->desc('List App Store Connect webhooks of an app')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('appId', '', new Text(64), 'App ID.')
            ->param('limit', 50, new Range(1, 200), 'Maximum number of webhooks to return.', true)
            ->inject('request')
            ->inject('response')
            ->inject('cache')
            ->callback($this->action(...));
    }

    public function action(string $appId, int $limit, Request $request, Response $response, Cache $cache): void
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

        $webhooks = $this->webhooks($cache, $appId);

        $this->respond($response, [
            'data' => \array_map($this->webhookResource(...), \array_slice($webhooks, 0, $limit)),
            'links' => ['self' => $this->link($request)],
            'meta' => ['paging' => ['total' => \count($webhooks), 'limit' => $limit]],
        ]);
    }
}
