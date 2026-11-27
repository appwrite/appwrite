<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Google\Seeds\Stalls;

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
    public const string RESOURCE = 'stall';

    /**
     * Below the 30 second client timeout of the Google adapter, so a stalled upload still completes.
     */
    private const int MAX_SECONDS = 25;

    public static function getName(): string
    {
        return 'createDistributionGoogleSeedStall';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath(self::SEEDS . '/stalls')
            ->desc('Seed Google Play upload stall')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('packageName', '', new Text(0), 'Application package name.')
            ->param('seconds', 1, new Range(1, self::MAX_SECONDS), 'Seconds the next completed bundle upload of the package waits after the bundle is saved and before it answers. Applies once.')
            ->inject('response')
            ->inject('cache')
            ->callback($this->action(...));
    }

    public function action(string $packageName, int $seconds, Response $response, Cache $cache): void
    {
        $isDevelopment = System::getEnv('_APP_ENV', 'production') === 'development';

        if (!$isDevelopment) {
            throw new Exception(Exception::GENERAL_NOT_IMPLEMENTED);
        }

        if ($packageName === '') {
            throw new Exception(Exception::GENERAL_MOCK, 'A stall needs a package name');
        }

        if (!\is_array($cache->load($this->key(self::RESOURCE_APPLICATION, $packageName), self::TTL))) {
            throw new Exception(Exception::GENERAL_MOCK, 'Seed the application first');
        }

        $cache->save($this->key(self::RESOURCE, $packageName), (string) $seconds, '', self::TTL);

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->json([
                'packageName' => $packageName,
                'seconds' => $seconds,
            ]);
    }
}
