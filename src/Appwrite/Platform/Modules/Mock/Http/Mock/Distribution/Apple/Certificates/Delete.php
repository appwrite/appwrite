<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\Certificates;

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
        return 'deleteDistributionAppleCertificate';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath(self::PREFIX . '/v1/certificates/:certificateId')
            ->desc('Revoke App Store Connect certificate')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('certificateId', '', new Text(64), 'Certificate ID.')
            ->inject('request')
            ->inject('response')
            ->inject('cache')
            ->inject('lock')
            ->callback($this->action(...));
    }

    public function action(string $certificateId, Request $request, Response $response, Cache $cache, Lock $lock): void
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

        $this->locked($lock, self::LOCK_TEAM . $team['teamId'], function () use ($cache, $team, $certificateId, $response): void {
            $certificates = $this->certificates($cache, $team['teamId']);
            $remaining = \array_values(\array_filter($certificates, fn (array $certificate): bool => $certificate['id'] !== $certificateId));

            if (\count($remaining) === \count($certificates)) {
                $this->notFound($response, 'certificates', $certificateId);
                return;
            }

            $profiles = \array_map(function (array $profile) use ($certificateId): array {
                if (\in_array($certificateId, $profile[self::INTERNAL]['certificateIds'], true)) {
                    $profile['attributes']['profileState'] = self::PROFILE_STATE_INVALID;
                }

                return $profile;
            }, $this->profiles($cache, $team['teamId']));

            $cache->save(self::CACHE_CERTIFICATE_CERTIFICATES . $team['teamId'], $remaining, '', self::TTL);
            $cache->save(self::CACHE_PROFILE_PROFILES . $team['teamId'], $profiles, '', self::TTL);

            $response->noContent();
        });
    }
}
