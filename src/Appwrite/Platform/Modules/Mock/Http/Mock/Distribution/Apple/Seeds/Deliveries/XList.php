<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\Seeds\Deliveries;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\Base;
use Appwrite\Utopia\Response;
use Utopia\Cache\Cache;
use Utopia\System\System;
use Utopia\Validator\Text;

class XList extends Base
{
    public static function getName(): string
    {
        return 'listDistributionAppleSeedDeliveries';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath(self::SEEDS . '/deliveries')
            ->desc('List App Store Connect webhook deliveries')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('webhookId', '', new Text(64), 'Webhook ID.')
            ->inject('response')
            ->inject('cache')
            ->callback($this->action(...));
    }

    public function action(string $webhookId, Response $response, Cache $cache): void
    {
        $isDevelopment = System::getEnv('_APP_ENV', 'production') === 'development';

        if (!$isDevelopment) {
            throw new Exception(Exception::GENERAL_NOT_IMPLEMENTED);
        }

        $deliveries = $this->deliveries($cache, $webhookId);

        $response->json([
            'total' => \count($deliveries),
            'deliveries' => $deliveries,
        ]);
    }
}
