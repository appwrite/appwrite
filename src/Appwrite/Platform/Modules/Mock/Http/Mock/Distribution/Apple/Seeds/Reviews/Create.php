<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\Seeds\Reviews;

use Appwrite\Extend\Exception;
use Appwrite\Locking\Lock;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\Base;
use Appwrite\Utopia\Response;
use Utopia\Cache\Cache;
use Utopia\Client\Client;
use Utopia\System\System;
use Utopia\Validator\Nullable;
use Utopia\Validator\Range;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

class Create extends Base
{
    public static function getName(): string
    {
        return 'createDistributionAppleSeedReview';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath(self::SEEDS . '/reviews')
            ->desc('Seed App Store review outcome')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('appId', '', new Text(64), 'ID of the app the version belongs to.')
            ->param('version', '', new Text(64), 'Marketing version, as the App Store version string.')
            ->param('state', '', new WhiteList(self::VERSION_STATES, true), 'App version state the review moves the version to, applied now if the version is already submitted, else when it is submitted.')
            ->param('phasedDay', null, new Nullable(new Range(1, 7)), 'Phased release day, 1 to 7, for a version that is or becomes ready for distribution with a phased release.', true)
            ->inject('response')
            ->inject('cache')
            ->inject('lock')
            ->inject('clientForWebhooks')
            ->callback($this->action(...));
    }

    public function action(string $appId, string $version, string $state, ?int $phasedDay, Response $response, Cache $cache, Lock $lock, Client $clientForWebhooks): void
    {
        $isDevelopment = System::getEnv('_APP_ENV', 'production') === 'development';

        if (!$isDevelopment) {
            throw new Exception(Exception::GENERAL_NOT_IMPLEMENTED);
        }

        if (!\is_array($cache->load(self::CACHE_APP . $appId, self::TTL))) {
            throw new Exception(Exception::GENERAL_MOCK, 'App not found: ' . $appId);
        }

        if ($version === '') {
            throw new Exception(Exception::GENERAL_MOCK, 'A review needs a version');
        }

        $applied = false;

        foreach ($this->versions($cache, $appId) as $existing) {
            if ($existing['attributes']['versionString'] !== $version || \in_array($existing['attributes']['appVersionState'], self::VERSION_PREPARABLE, true)) {
                continue;
            }

            $existing[self::INTERNAL]['phasedDay'] = $phasedDay;
            $this->versionTransition($cache, $lock, $clientForWebhooks, $existing, $state);
            $applied = true;
        }

        $this->saveVersionReview($cache, $lock, $appId, $version, $applied ? null : ['state' => $state, 'phasedDay' => $phasedDay]);

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->json([
                'appId' => $appId,
                'version' => $version,
                'state' => $state,
                'phasedDay' => $phasedDay,
                'applied' => $applied,
            ]);
    }
}
