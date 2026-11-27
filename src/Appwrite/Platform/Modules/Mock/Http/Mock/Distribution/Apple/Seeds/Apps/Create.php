<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\Seeds\Apps;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\Base;
use Appwrite\Utopia\Response;
use Utopia\Cache\Cache;
use Utopia\System\System;
use Utopia\Validator\Text;

class Create extends Base
{
    public static function getName(): string
    {
        return 'createDistributionAppleSeedApp';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath(self::SEEDS . '/apps')
            ->desc('Seed App Store Connect app')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('issuerId', '', new Text(128), 'Issuer ID of the team that owns the app.')
            ->param('bundleId', '', new Text(155), 'App bundle ID.')
            ->param('name', '', new Text(128), 'App name.')
            ->param('sku', '', new Text(128, 0), 'App SKU. Defaults to the bundle ID.', true)
            ->param('primaryLocale', 'en-US', new Text(16), 'App primary locale.', true)
            ->inject('response')
            ->inject('cache')
            ->callback($this->action(...));
    }

    public function action(string $issuerId, string $bundleId, string $name, string $sku, string $primaryLocale, Response $response, Cache $cache): void
    {
        $isDevelopment = System::getEnv('_APP_ENV', 'production') === 'development';

        if (!$isDevelopment) {
            throw new Exception(Exception::GENERAL_NOT_IMPLEMENTED);
        }

        $apps = $cache->load(self::CACHE_APPS . $issuerId, self::TTL);
        $apps = \is_array($apps) ? $apps : [];

        foreach ($apps as $app) {
            if ($app['attributes']['bundleId'] === $bundleId) {
                throw new Exception(Exception::GENERAL_MOCK, 'An app with this bundle ID already exists for this issuer');
            }
        }

        $app = [
            'type' => 'apps',
            'id' => (string) \random_int(1_000_000_000, 9_999_999_999),
            'attributes' => [
                'bundleId' => $bundleId,
                'name' => $name,
                'sku' => $sku === '' ? $bundleId : $sku,
                'primaryLocale' => $primaryLocale,
            ],
        ];

        $apps[] = $app;

        $cache->save(self::CACHE_APPS . $issuerId, $apps);
        $cache->save(self::CACHE_APP . $app['id'], ['issuerId' => $issuerId]);

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->json(['data' => $app]);
    }
}
