<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\AppStoreVersions;

use Appwrite\Extend\Exception;
use Appwrite\Locking\Lock;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\Base;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Cache\Cache;
use Utopia\System\System;
use Utopia\Validator\Assoc;

class Create extends Base
{
    private const array ALLOWED_KEYS = [
        'data' => ['type', 'attributes', 'relationships'],
        'attributes' => ['platform', 'versionString', 'copyright', 'reviewType', 'releaseType', 'earliestReleaseDate'],
        'relationships' => ['app', 'build'],
    ];

    public static function getName(): string
    {
        return 'createDistributionAppleAppStoreVersion';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath(self::PREFIX . '/v1/appStoreVersions')
            ->desc('Create App Store Connect version')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('data', [], new Assoc(), 'Version resource with `platform` and `versionString` attributes and an `app` relationship.')
            ->inject('request')
            ->inject('response')
            ->inject('cache')
            ->inject('lock')
            ->callback($this->action(...));
    }

    /**
     * @param array<string, mixed> $data
     */
    public function action(array $data, Request $request, Response $response, Cache $cache, Lock $lock): void
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
        $attributes = \is_array($data['attributes'] ?? null) ? \array_intersect_key($data['attributes'], \array_flip(self::ALLOWED_KEYS['attributes'])) : [];
        $relationships = \is_array($data['relationships'] ?? null) ? \array_intersect_key($data['relationships'], \array_flip(self::ALLOWED_KEYS['relationships'])) : [];

        if (($data['type'] ?? null) !== self::VERSION_TYPE) {
            $this->versionConflict($response, self::CODE_TYPE_INVALID, 'The provided entity type is invalid', "The type must be '" . self::VERSION_TYPE . "'.", '/data/type');
            return;
        }

        foreach (['platform', 'versionString'] as $name) {
            if (!\is_string($attributes[$name] ?? null) || $attributes[$name] === '') {
                $this->versionConflict($response, self::CODE_ATTRIBUTE_REQUIRED, 'The provided entity is missing a required attribute', "You must provide a value for the attribute '{$name}' with this request", '/data/attributes/' . $name);
                return;
            }
        }

        if ($attributes['platform'] !== self::VERSION_PLATFORM) {
            $this->versionConflict($response, self::CODE_ATTRIBUTE_INVALID, 'An attribute value is invalid.', "The platform '{$attributes['platform']}' is not supported.", '/data/attributes/platform');
            return;
        }

        $releaseType = $attributes['releaseType'] ?? 'AFTER_APPROVAL';

        if (!\in_array($releaseType, self::VERSION_RELEASE_TYPES, true)) {
            $this->versionConflict($response, self::CODE_ATTRIBUTE_INVALID, 'An attribute value is invalid.', "The attribute 'releaseType' has an invalid value.", '/data/attributes/releaseType');
            return;
        }

        $appId = $this->versionLinkage($relationships, 'app', 'apps');

        if ($appId === null) {
            $this->versionConflict($response, self::CODE_RELATIONSHIP_REQUIRED, 'The provided entity is missing a required relationship', "You must provide a value for the relationship 'app' with this request", '/data/relationships/app');
            return;
        }

        if (!$this->owns($cache, $key['issuerId'], $appId)) {
            $this->versionConflict($response, self::CODE_RELATIONSHIP_INVALID, 'The provided entity includes a relationship with an invalid value', "The app '{$appId}' does not exist.", '/data/relationships/app');
            return;
        }

        foreach ($this->versions($cache, $appId) as $existing) {
            if ($existing['attributes']['versionString'] === $attributes['versionString']) {
                $this->versionConflict($response, self::CODE_ATTRIBUTE_INVALID, 'An attribute value is invalid.', "A version with the version string '{$attributes['versionString']}' already exists.", '/data/attributes/versionString');
                return;
            }

            if (!\in_array($existing['attributes']['appVersionState'], self::VERSION_RELEASED, true)) {
                $this->versionConflict($response, self::CODE_ATTRIBUTE_INVALID, 'An attribute value is invalid.', 'You cannot create a new version of the App in the current state.', '/data/attributes/versionString');
                return;
            }
        }

        $buildId = null;

        if (isset($relationships['build'])) {
            $buildId = $this->versionLinkage($relationships, 'build', 'builds');
            $build = $buildId === null ? null : $this->build($cache, $buildId);

            if (
                $build === null
                || $cache->load(self::CACHE_BUILD . $buildId, self::TTL) !== $appId
                || ($build[self::INTERNAL]['marketingVersion'] ?? null) !== $attributes['versionString']
                || ($build['attributes']['processingState'] ?? null) !== 'VALID'
            ) {
                $this->versionConflict($response, self::CODE_RELATIONSHIP_INVALID, 'The provided entity includes a relationship with an invalid value', 'The build is not valid for this version.', '/data/relationships/build');
                return;
            }
        }

        $version = [
            'type' => self::VERSION_TYPE,
            'id' => $this->uuid(),
            'attributes' => [
                'platform' => self::VERSION_PLATFORM,
                'versionString' => $attributes['versionString'],
                'appStoreState' => self::VERSION_PREPARE_FOR_SUBMISSION,
                'appVersionState' => self::VERSION_PREPARE_FOR_SUBMISSION,
                'copyright' => $attributes['copyright'] ?? null,
                'reviewType' => $attributes['reviewType'] ?? 'APP_STORE',
                'releaseType' => $releaseType,
                'earliestReleaseDate' => $attributes['earliestReleaseDate'] ?? null,
                'downloadable' => true,
                'createdDate' => (new \DateTimeImmutable())->format(\DateTimeInterface::RFC3339_EXTENDED),
            ],
            self::INTERNAL => [
                'appId' => $appId,
                'buildId' => $buildId,
                'phased' => null,
                'phasedDay' => null,
                'localizations' => [],
            ],
        ];

        $this->saveVersion($cache, $lock, $version);

        $this->respond($response, [
            'data' => $this->versionResource($version),
            'links' => ['self' => $this->link($request)],
        ], Response::STATUS_CODE_CREATED);
    }
}
