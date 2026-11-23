<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\Seeds\BetaReviews;

use Appwrite\Extend\Exception;
use Appwrite\Locking\Lock;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\Base;
use Appwrite\Utopia\Response;
use Utopia\Cache\Cache;
use Utopia\Client\Client;
use Utopia\System\System;
use Utopia\Validator\Nullable;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

class Create extends Base
{
    public static function getName(): string
    {
        return 'createDistributionAppleSeedBetaReview';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath(self::SEEDS . '/beta-reviews')
            ->desc('Seed App Store Connect beta review outcome')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('appId', '', new Text(64), 'ID of the app the build belongs to.')
            ->param('build', '', new Text(64), 'Build number, as `CFBundleVersion`. When no such build exists yet, the outcome applies once it does.')
            ->param('state', '', new WhiteList(self::BETA_EXTERNAL_STATES, true), 'External build state, an `ExternalBetaState` value.')
            ->param('internalState', null, new Nullable(new WhiteList(self::BETA_INTERNAL_STATES, true)), 'Internal build state, an `InternalBetaState` value. Defaults to the current one.', true)
            ->inject('response')
            ->inject('cache')
            ->inject('lock')
            ->inject('clientForWebhooks')
            ->callback($this->action(...));
    }

    public function action(string $appId, string $build, string $state, ?string $internalState, Response $response, Cache $cache, Lock $lock, Client $clientForWebhooks): void
    {
        $isDevelopment = System::getEnv('_APP_ENV', 'production') === 'development';

        if (!$isDevelopment) {
            throw new Exception(Exception::GENERAL_NOT_IMPLEMENTED);
        }

        if (!\is_array($cache->load(self::CACHE_APP . $appId, self::TTL))) {
            throw new Exception(Exception::GENERAL_MOCK, 'App not found: ' . $appId);
        }

        $existing = $this->betaBuildByVersion($cache, $appId, $build);

        if ($existing === null) {
            $cache->save(self::CACHE_BETA_REVIEW . $appId . ':' . $build, ['state' => $state, 'internalState' => $internalState], '', self::TTL);

            $response
                ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
                ->json(['appId' => $appId, 'build' => $build, 'state' => $state, 'internalState' => $internalState]);

            return;
        }

        $detail = $this->updateBetaDetail($cache, $lock, $clientForWebhooks, $appId, $existing, $state, $internalState);

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->json(['data' => $this->betaDetailResource($detail)]);
    }
}
