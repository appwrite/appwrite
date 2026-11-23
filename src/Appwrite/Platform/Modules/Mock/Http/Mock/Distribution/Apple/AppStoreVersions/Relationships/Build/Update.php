<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\AppStoreVersions\Relationships\Build;

use Appwrite\Extend\Exception;
use Appwrite\Locking\Lock;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\Base;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Cache\Cache;
use Utopia\System\System;
use Utopia\Validator\Assoc;
use Utopia\Validator\Text;

class Update extends Base
{
    private const array ALLOWED_KEYS = [
        'data' => ['type', 'id'],
    ];

    public static function getName(): string
    {
        return 'updateDistributionAppleAppStoreVersionBuild';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath(self::PREFIX . '/v1/appStoreVersions/:appStoreVersionId/relationships/build')
            ->desc('Update App Store Connect version build')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('appStoreVersionId', '', new Text(64), 'Version ID.')
            ->param('data', [], new Assoc(), 'Build linkage, with `type` builds and the build `id`.')
            ->inject('request')
            ->inject('response')
            ->inject('cache')
            ->inject('lock')
            ->callback($this->action(...));
    }

    /**
     * @param array<string, mixed> $data
     */
    public function action(string $appStoreVersionId, array $data, Request $request, Response $response, Cache $cache, Lock $lock): void
    {
        $isDevelopment = System::getEnv('_APP_ENV', 'production') === 'development';

        if (!$isDevelopment) {
            throw new Exception(Exception::GENERAL_NOT_IMPLEMENTED);
        }

        $key = $this->authorize($request, $response, $cache);

        if ($key === null) {
            return;
        }

        $data = \array_intersect_key($data, \array_flip(self::ALLOWED_KEYS['data']));

        $version = $this->version($cache, $appStoreVersionId);

        if ($version === null || !$this->ownsVersion($cache, $key['issuerId'], $version)) {
            $this->notFound($response, self::VERSION_TYPE, $appStoreVersionId);
            return;
        }

        $buildId = $this->versionLinkage(['build' => ['data' => $data]], 'build', 'builds');

        if ($buildId === null) {
            $this->versionConflict($response, self::CODE_TYPE_INVALID, 'The provided entity type is invalid', "The linkage must have type 'builds' and an id.", '/data');
            return;
        }

        if (!\in_array($version['attributes']['appVersionState'], self::VERSION_PREPARABLE, true)) {
            $this->versionConflict($response, self::VERSION_CODE_STATE, 'The request cannot be fulfilled because of the state of another resource.', "The version is {$version['attributes']['appVersionState']} and its build cannot change.");
            return;
        }

        $build = $this->build($cache, $buildId);
        $owner = $cache->load(self::CACHE_BUILD . $buildId, self::TTL);

        if ($build === null || $owner !== $version[self::INTERNAL]['appId']) {
            $this->notFound($response, 'builds', $buildId);
            return;
        }

        if (($build['attributes']['processingState'] ?? null) !== 'VALID' || ($build[self::INTERNAL]['marketingVersion'] ?? null) !== $version['attributes']['versionString']) {
            $this->versionConflict($response, self::CODE_RELATIONSHIP_INVALID, 'The provided entity includes a relationship with an invalid value', "The build '{$buildId}' is not valid or does not match the version string '{$version['attributes']['versionString']}'.", '/data/id');
            return;
        }

        $version[self::INTERNAL]['buildId'] = $buildId;

        $this->saveVersion($cache, $lock, $version);

        $response->noContent();
    }
}
