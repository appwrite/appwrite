<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\Seeds\Builds;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\Base;
use Appwrite\Utopia\Response;
use Utopia\Cache\Cache;
use Utopia\Database\Validator\Datetime as DatetimeValidator;
use Utopia\System\System;
use Utopia\Validator\Nullable;
use Utopia\Validator\Text;

class Create extends Base
{
    public static function getName(): string
    {
        return 'createDistributionAppleSeedBuild';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath(self::SEEDS . '/builds')
            ->desc('Seed App Store Connect build')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('appId', '', new Text(64), 'ID of the app the build belongs to.')
            ->param('version', '', new Text(64), 'Build number, as `CFBundleVersion`.')
            ->param('uploadedDate', null, new Nullable(new DatetimeValidator()), 'Upload time in [ISO 8601](https://www.iso.org/iso-8601-date-and-time-format.html) format. Defaults to now.', true)
            ->inject('response')
            ->inject('cache')
            ->callback($this->action(...));
    }

    public function action(string $appId, string $version, ?string $uploadedDate, Response $response, Cache $cache): void
    {
        $isDevelopment = System::getEnv('_APP_ENV', 'production') === 'development';

        if (!$isDevelopment) {
            throw new Exception(Exception::GENERAL_NOT_IMPLEMENTED);
        }

        if (!\is_array($cache->load(self::CACHE_APP . $appId, self::TTL))) {
            throw new Exception(Exception::GENERAL_MOCK, 'App not found: ' . $appId);
        }

        $builds = $cache->load(self::CACHE_BUILDS . $appId, self::TTL);
        $builds = \is_array($builds) ? $builds : [];

        $build = [
            'type' => 'builds',
            'id' => $this->uuid(),
            'attributes' => [
                'version' => $version,
                'uploadedDate' => (new \DateTimeImmutable($uploadedDate ?? 'now'))->format(\DateTimeInterface::RFC3339_EXTENDED),
                'processingState' => 'VALID',
            ],
        ];

        $builds[] = $build;

        $cache->save(self::CACHE_BUILDS . $appId, $builds);

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->json(['data' => $build]);
    }
}
