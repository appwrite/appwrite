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
use Utopia\Validator\Text;

class Update extends Base
{
    private const array ALLOWED_KEYS = [
        'data' => ['type', 'id', 'attributes'],
        'attributes' => ['versionString', 'copyright', 'reviewType', 'releaseType', 'earliestReleaseDate', 'downloadable'],
    ];

    public static function getName(): string
    {
        return 'updateDistributionAppleAppStoreVersion';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath(self::PREFIX . '/v1/appStoreVersions/:appStoreVersionId')
            ->desc('Update App Store Connect version')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('appStoreVersionId', '', new Text(64), 'Version ID.')
            ->param('data', [], new Assoc(), 'Version resource with the attributes to change.')
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
        $attributes = \is_array($data['attributes'] ?? null) ? \array_intersect_key($data['attributes'], \array_flip(self::ALLOWED_KEYS['attributes'])) : [];

        $version = $this->version($cache, $appStoreVersionId);

        if ($version === null || !$this->ownsVersion($cache, $key['issuerId'], $version)) {
            $this->notFound($response, self::VERSION_TYPE, $appStoreVersionId);
            return;
        }

        if (($data['type'] ?? null) !== self::VERSION_TYPE || ($data['id'] ?? null) !== $appStoreVersionId) {
            $this->versionConflict($response, self::CODE_TYPE_INVALID, 'The provided entity type is invalid', 'The type and id must match the version being updated.', '/data');
            return;
        }

        if (!\in_array($version['attributes']['appVersionState'], self::VERSION_PREPARABLE, true)) {
            $this->versionConflict($response, self::VERSION_CODE_STATE, 'The request cannot be fulfilled because of the state of another resource.', "The version is {$version['attributes']['appVersionState']} and cannot be edited.");
            return;
        }

        if (isset($attributes['releaseType']) && !\in_array($attributes['releaseType'], self::VERSION_RELEASE_TYPES, true)) {
            $this->versionConflict($response, self::CODE_ATTRIBUTE_INVALID, 'An attribute value is invalid.', "The attribute 'releaseType' has an invalid value.", '/data/attributes/releaseType');
            return;
        }

        if (isset($attributes['versionString'])) {
            if (!\is_string($attributes['versionString']) || $attributes['versionString'] === '') {
                $this->versionConflict($response, self::CODE_ATTRIBUTE_INVALID, 'An attribute value is invalid.', "The attribute 'versionString' has an invalid value.", '/data/attributes/versionString');
                return;
            }

            foreach ($this->versions($cache, $version[self::INTERNAL]['appId']) as $existing) {
                if ($existing['id'] !== $appStoreVersionId && $existing['attributes']['versionString'] === $attributes['versionString']) {
                    $this->versionConflict($response, self::CODE_ATTRIBUTE_INVALID, 'An attribute value is invalid.', "A version with the version string '{$attributes['versionString']}' already exists.", '/data/attributes/versionString');
                    return;
                }
            }

            $build = $version[self::INTERNAL]['buildId'] === null ? null : $this->build($cache, $version[self::INTERNAL]['buildId']);

            if ($build !== null && ($build[self::INTERNAL]['marketingVersion'] ?? null) !== $attributes['versionString']) {
                $version[self::INTERNAL]['buildId'] = null;
            }
        }

        $version['attributes'] = \array_replace($version['attributes'], $attributes);

        $this->saveVersion($cache, $lock, $version);

        $this->respond($response, [
            'data' => $this->versionResource($version),
            'links' => ['self' => $this->link($request)],
        ]);
    }
}
