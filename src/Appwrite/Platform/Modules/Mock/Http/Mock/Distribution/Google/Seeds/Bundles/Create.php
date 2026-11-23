<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Google\Seeds\Bundles;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Google\Base;
use Appwrite\Utopia\Response;
use Utopia\Cache\Cache;
use Utopia\System\System;
use Utopia\Validator\Range;
use Utopia\Validator\Text;

class Create extends Base
{
    private const int MAX_VERSION_CODE = 2100000000;
    private const int SHA256_LENGTH = 64;

    public static function getName(): string
    {
        return 'createDistributionGoogleSeedBundle';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath(self::SEEDS . '/bundles')
            ->desc('Seed Google Play bundle version code')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('packageName', '', new Text(0), 'Application package name.')
            ->param('sha256', '', new Text(self::SHA256_LENGTH, self::SHA256_LENGTH), 'Hex SHA-256 of the bundle file.')
            ->param('versionCode', 1, new Range(1, self::MAX_VERSION_CODE), 'Version code an upload of this bundle reports, as read from its manifest.')
            ->inject('response')
            ->inject('cache')
            ->callback($this->action(...));
    }

    public function action(string $packageName, string $sha256, int $versionCode, Response $response, Cache $cache): void
    {
        $isDevelopment = System::getEnv('_APP_ENV', 'production') === 'development';

        if (!$isDevelopment) {
            throw new Exception(Exception::GENERAL_NOT_IMPLEMENTED);
        }

        $sha256 = \strtolower($sha256);

        if (!\ctype_xdigit($sha256)) {
            throw new Exception(Exception::GENERAL_MOCK, 'SHA-256 must be hexadecimal');
        }

        $cache->save($this->key(self::RESOURCE_BUNDLE, $packageName . ':' . $sha256), (string) $versionCode, '', self::TTL);

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->json([
                'packageName' => $packageName,
                'sha256' => $sha256,
                'versionCode' => $versionCode,
            ]);
    }
}
