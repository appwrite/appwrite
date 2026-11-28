<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\Seeds\Teams;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\Base;
use Appwrite\Utopia\Response;
use Utopia\Cache\Cache;
use Utopia\System\System;
use Utopia\Validator\Boolean;
use Utopia\Validator\Range;
use Utopia\Validator\Text;

class Create extends Base
{
    private const int LENGTH = 10;

    public static function getName(): string
    {
        return 'createDistributionAppleSeedTeam';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath(self::SEEDS . '/teams')
            ->desc('Seed App Store Connect team')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('issuerId', '', new Text(128), 'Issuer ID whose API keys act for the team.')
            ->param('teamId', '', new Text(self::LENGTH, self::LENGTH), 'Ten character team ID, returned as the bundle ID `seedId` and profile team identifier.')
            ->param('certificates', 3, new Range(0, 100), 'Maximum number of current certificates of each type.', true)
            ->param('provisioning', true, new Boolean(true), 'Whether the team\'s API keys may manage certificates, bundle IDs and profiles.', true)
            ->param('profileDays', self::PROFILE_DAYS, new Range(1, self::PROFILE_DAYS), 'Days until a provisioning profile created for the team expires.', true)
            ->inject('response')
            ->inject('cache')
            ->callback($this->action(...));
    }

    public function action(string $issuerId, string $teamId, int $certificates, bool $provisioning, int $profileDays, Response $response, Cache $cache): void
    {
        $isDevelopment = System::getEnv('_APP_ENV', 'production') === 'development';

        if (!$isDevelopment) {
            throw new Exception(Exception::GENERAL_NOT_IMPLEMENTED);
        }

        if (!\ctype_alnum($teamId) || \strtoupper($teamId) !== $teamId) {
            throw new Exception(Exception::GENERAL_MOCK, 'Team ID must be ten uppercase letters or digits');
        }

        $cache->save(self::CACHE_TEAM_SEEDS . $issuerId, [
            'teamId' => $teamId,
            'certificates' => $certificates,
            'provisioning' => $provisioning,
            'profileDays' => $profileDays,
        ], '', self::TTL);

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->json([
                'data' => [
                    'type' => 'teams',
                    'id' => $teamId,
                    'attributes' => [
                        'issuerId' => $issuerId,
                        'certificates' => $certificates,
                        'provisioning' => $provisioning,
                        'profileDays' => $profileDays,
                    ],
                ],
            ]);
    }
}
