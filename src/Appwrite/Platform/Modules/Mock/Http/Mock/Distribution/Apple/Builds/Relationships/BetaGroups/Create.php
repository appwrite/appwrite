<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\Builds\Relationships\BetaGroups;

use Appwrite\Extend\Exception;
use Appwrite\Locking\Lock;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\Base;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Cache\Cache;
use Utopia\System\System;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Assoc;
use Utopia\Validator\Text;

class Create extends Base
{
    private const string TYPE = 'betaGroups';
    private const array ALLOWED_KEYS = [
        'data' => ['type', 'id'],
    ];

    public static function getName(): string
    {
        return 'createDistributionAppleBuildBetaGroups';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath(self::PREFIX . '/v1/builds/:buildId/relationships/betaGroups')
            ->desc('Add App Store Connect build to beta groups')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('buildId', '', new Text(64), 'Build ID.')
            ->param('data', [], new ArrayList(new Assoc()), 'Beta group linkages, each `{type: "betaGroups", id}`.')
            ->inject('request')
            ->inject('response')
            ->inject('cache')
            ->inject('lock')
            ->callback($this->action(...));
    }

    /**
     * @param list<array<string, mixed>> $data
     */
    public function action(string $buildId, array $data, Request $request, Response $response, Cache $cache, Lock $lock): void
    {
        $isDevelopment = System::getEnv('_APP_ENV', 'production') === 'development';

        if (!$isDevelopment) {
            throw new Exception(Exception::GENERAL_NOT_IMPLEMENTED);
        }

        $key = $this->authorize($request, $response, $cache);

        if ($key === null) {
            return;
        }

        $linkages = \array_map(fn (array $linkage): array => \array_intersect_key($linkage, \array_flip(self::ALLOWED_KEYS['data'])), $data);

        $owned = $this->betaBuild($cache, $key['issuerId'], $buildId);

        if ($owned === null) {
            $this->notFound($response, 'builds', $buildId);
            return;
        }

        if ($linkages === []) {
            $this->error($response, Response::STATUS_CODE_CONFLICT, self::CODE_RELATIONSHIP_REQUIRED, 'The provided entity is missing a required relationship', 'You must provide at least one beta group.', ['pointer' => '/data']);
            return;
        }

        $groups = [];

        foreach ($linkages as $index => $linkage) {
            $groupId = $linkage['id'] ?? null;

            if (($linkage['type'] ?? null) !== self::TYPE || !\is_string($groupId) || $groupId === '') {
                $this->error($response, Response::STATUS_CODE_CONFLICT, self::CODE_TYPE_INVALID, 'The provided entity type is invalid', "The type must be '" . self::TYPE . "' with an id.", ['pointer' => "/data/{$index}"]);
                return;
            }

            $group = $this->betaGroup($cache, $owned['appId'], $groupId);

            if ($group === null) {
                $this->notFound($response, self::TYPE, $groupId);
                return;
            }

            if (!$group['attributes']['isInternalGroup'] && ($owned['build']['attributes']['buildAudienceType'] ?? null) === self::BETA_INTERNAL_ONLY) {
                $this->error($response, Response::STATUS_CODE_CONFLICT, self::CODE_RELATIONSHIP_INVALID, 'The provided entity includes a relationship with an invalid value', 'Builds for internal testing only cannot be added to an external group.', ['pointer' => "/data/{$index}"]);
                return;
            }

            $groups[$groupId] = $group;
        }

        foreach ($groups as $group) {
            $group[self::INTERNAL]['builds'] = \array_values(\array_unique([...$group[self::INTERNAL]['builds'], $buildId]));
            $this->saveBetaGroup($cache, $lock, $owned['appId'], $group);
        }

        $response->noContent();
    }
}
