<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\Seeds\Groups;

use Appwrite\Extend\Exception;
use Appwrite\Locking\Lock;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\Base;
use Appwrite\Utopia\Response;
use Utopia\Cache\Cache;
use Utopia\System\System;
use Utopia\Validator\Boolean;
use Utopia\Validator\Text;

class Create extends Base
{
    public static function getName(): string
    {
        return 'createDistributionAppleSeedGroup';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath(self::SEEDS . '/groups')
            ->desc('Seed App Store Connect beta group')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('appId', '', new Text(64), 'ID of the app the group belongs to.')
            ->param('name', '', new Text(128), 'Group name.')
            ->param('internal', true, new Boolean(true), 'Whether the group is an internal tester group.', true)
            ->param('hasAccessToAllBuilds', false, new Boolean(true), 'Whether every build is available to the group without being added. Internal groups only.', true)
            ->inject('response')
            ->inject('cache')
            ->inject('lock')
            ->callback($this->action(...));
    }

    public function action(string $appId, string $name, bool|string $internal, bool|string $hasAccessToAllBuilds, Response $response, Cache $cache, Lock $lock): void
    {
        $isDevelopment = System::getEnv('_APP_ENV', 'production') === 'development';

        if (!$isDevelopment) {
            throw new Exception(Exception::GENERAL_NOT_IMPLEMENTED);
        }

        if (!\is_array($cache->load(self::CACHE_APP . $appId, self::TTL))) {
            throw new Exception(Exception::GENERAL_MOCK, 'App not found: ' . $appId);
        }

        $internal = \filter_var($internal, FILTER_VALIDATE_BOOLEAN);
        $hasAccessToAllBuilds = \filter_var($hasAccessToAllBuilds, FILTER_VALIDATE_BOOLEAN);

        if ($hasAccessToAllBuilds && !$internal) {
            throw new Exception(Exception::GENERAL_MOCK, 'Only internal groups can have access to all builds');
        }

        foreach ($this->betaGroups($cache, $appId) as $group) {
            if ($group['attributes']['name'] === $name) {
                throw new Exception(Exception::GENERAL_MOCK, 'A beta group with this name already exists for this app');
            }
        }

        $group = [
            'type' => 'betaGroups',
            'id' => $this->uuid(),
            'attributes' => [
                'name' => $name,
                'isInternalGroup' => $internal,
                'hasAccessToAllBuilds' => $hasAccessToAllBuilds,
                'publicLinkEnabled' => false,
                'feedbackEnabled' => true,
                'createdDate' => (new \DateTimeImmutable())->format(\DateTimeInterface::RFC3339_EXTENDED),
            ],
            self::INTERNAL => [
                'builds' => [],
            ],
        ];

        $this->saveBetaGroup($cache, $lock, $appId, $group);

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->json(['data' => $group]);
    }
}
