<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\Profiles;

use Appwrite\Extend\Exception;
use Appwrite\Locking\Lock;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\Base;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Cache\Cache;
use Utopia\System\System;
use Utopia\Validator\Text;

class Delete extends Base
{
    public static function getName(): string
    {
        return 'deleteDistributionAppleProfile';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath(self::PREFIX . '/v1/profiles/:profileId')
            ->desc('Delete App Store Connect provisioning profile')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('profileId', '', new Text(64), 'Profile ID.')
            ->inject('request')
            ->inject('response')
            ->inject('cache')
            ->inject('lock')
            ->callback($this->action(...));
    }

    public function action(string $profileId, Request $request, Response $response, Cache $cache, Lock $lock): void
    {
        $isDevelopment = System::getEnv('_APP_ENV', 'production') === 'development';

        if (!$isDevelopment) {
            throw new Exception(Exception::GENERAL_NOT_IMPLEMENTED);
        }

        $key = $this->authorize($request, $response, $cache);

        if ($key === null) {
            return;
        }

        $team = $this->provisioning($response, $cache, $key['issuerId']);

        if ($team === null) {
            return;
        }

        $this->locked($lock, self::LOCK_TEAM . $team['teamId'], function () use ($cache, $team, $profileId, $response): void {
            $profiles = $this->profiles($cache, $team['teamId']);
            $remaining = \array_values(\array_filter($profiles, fn (array $profile): bool => $profile['id'] !== $profileId));

            if (\count($remaining) === \count($profiles)) {
                $this->notFound($response, 'profiles', $profileId);
                return;
            }

            $cache->save(self::CACHE_PROFILE_PROFILES . $team['teamId'], $remaining, '', self::TTL);

            $response->noContent();
        });
    }
}
