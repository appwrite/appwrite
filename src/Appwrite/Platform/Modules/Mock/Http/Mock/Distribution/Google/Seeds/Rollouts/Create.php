<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Google\Seeds\Rollouts;

use Appwrite\Extend\Exception;
use Appwrite\Locking\Lock;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Google\Base;
use Appwrite\Utopia\Response;
use Utopia\Cache\Cache;
use Utopia\System\System;
use Utopia\Validator\Range;
use Utopia\Validator\Text;

class Create extends Base
{
    public const string RESOURCE = 'rollout';

    public static function getName(): string
    {
        return 'createDistributionGoogleSeedRollout';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath(self::SEEDS . '/rollouts')
            ->desc('Seed Google Play staged rollout')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('packageName', '', new Text(0), 'Application package name.')
            ->param('track', '', new Text(0), 'Track the rollout applies to.')
            ->param('fraction', 0.5, new Range(0, 1, Range::TYPE_FLOAT), 'Fraction of users, greater than 0 and less than 1, that the next commit rolling a completed release out to the track reaches instead. Applies once.')
            ->inject('response')
            ->inject('cache')
            ->inject('lock')
            ->callback($this->action(...));
    }

    public function action(string $packageName, string $track, float $fraction, Response $response, Cache $cache, Lock $lock): void
    {
        $isDevelopment = System::getEnv('_APP_ENV', 'production') === 'development';

        if (!$isDevelopment) {
            throw new Exception(Exception::GENERAL_NOT_IMPLEMENTED);
        }

        if ($track === '') {
            throw new Exception(Exception::GENERAL_MOCK, 'A rollout needs a track');
        }

        if ($fraction <= 0.0 || $fraction >= 1.0) {
            throw new Exception(Exception::GENERAL_MOCK, 'A staged rollout needs a fraction greater than 0 and less than 1');
        }

        if (!\is_array($cache->load($this->key(self::RESOURCE_APPLICATION, $packageName), self::TTL))) {
            throw new Exception(Exception::GENERAL_MOCK, 'Seed the application first');
        }

        $rollout = [
            'packageName' => $packageName,
            'track' => $track,
            'fraction' => $fraction,
        ];

        $this->locked($lock, $packageName, fn () => $cache->save($this->key(self::RESOURCE, $packageName), $rollout, '', self::TTL));

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->json($rollout);
    }
}
