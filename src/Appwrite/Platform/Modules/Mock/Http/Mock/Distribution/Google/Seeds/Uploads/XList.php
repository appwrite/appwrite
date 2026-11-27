<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Google\Seeds\Uploads;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Google\Base;
use Appwrite\Utopia\Response;
use Utopia\Cache\Cache;
use Utopia\System\System;
use Utopia\Validator\Text;

class XList extends Base
{
    public const string SESSIONS = 'sessions';
    public const string COMPLETED = 'completed';
    public const string BYTES = 'bytes';

    public static function getName(): string
    {
        return 'listDistributionGoogleSeedUploads';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath(self::SEEDS . '/uploads')
            ->desc('List Google Play bundle upload counters')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('packageName', '', new Text(0), 'Application package name.')
            ->inject('response')
            ->inject('cache')
            ->callback($this->action(...));
    }

    public function action(string $packageName, Response $response, Cache $cache): void
    {
        $isDevelopment = System::getEnv('_APP_ENV', 'production') === 'development';

        if (!$isDevelopment) {
            throw new Exception(Exception::GENERAL_NOT_IMPLEMENTED);
        }

        $uploads = $cache->load($this->key(self::RESOURCE_UPLOADS, $packageName), self::TTL);
        $uploads = \is_array($uploads) ? $uploads : [];

        $response->json([
            self::SESSIONS => (int) ($uploads[self::SESSIONS] ?? 0),
            self::COMPLETED => (int) ($uploads[self::COMPLETED] ?? 0),
            self::BYTES => (int) ($uploads[self::BYTES] ?? 0),
        ]);
    }
}
