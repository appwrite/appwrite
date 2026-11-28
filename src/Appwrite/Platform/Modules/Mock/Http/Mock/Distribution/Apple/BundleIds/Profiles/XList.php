<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\BundleIds\Profiles;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\Base;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Cache\Cache;
use Utopia\System\System;
use Utopia\Validator\Range;
use Utopia\Validator\Text;

class XList extends Base
{
    public static function getName(): string
    {
        return 'listDistributionAppleBundleIdProfiles';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath(self::PREFIX . '/v1/bundleIds/:bundleId/profiles')
            ->desc('List App Store Connect bundle ID profiles')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('bundleId', '', new Text(64), 'Bundle ID resource ID.')
            ->param('limit', 20, new Range(1, 200), 'Maximum number of profiles to return.', true)
            ->inject('request')
            ->inject('response')
            ->inject('cache')
            ->callback($this->action(...));
    }

    public function action(string $bundleId, int $limit, Request $request, Response $response, Cache $cache): void
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

        if ($this->bundle($cache, $team['teamId'], $bundleId) === null) {
            $this->notFound($response, self::BUNDLE_TYPE, $bundleId);
            return;
        }

        $data = [];

        foreach ($this->profiles($cache, $team['teamId']) as $profile) {
            if ($profile[self::INTERNAL]['bundleId'] !== $bundleId) {
                continue;
            }

            $data[] = $this->profileResource($profile);

            if (\count($data) === $limit) {
                break;
            }
        }

        $this->respond($response, [
            'data' => $data,
            'links' => ['self' => $this->link($request)],
        ]);
    }
}
