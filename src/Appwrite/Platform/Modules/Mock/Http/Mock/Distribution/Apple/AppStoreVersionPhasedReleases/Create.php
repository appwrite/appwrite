<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\AppStoreVersionPhasedReleases;

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
        'attributes' => ['phasedReleaseState'],
        'relationships' => ['appStoreVersion'],
    ];

    public static function getName(): string
    {
        return 'createDistributionAppleAppStoreVersionPhasedRelease';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath(self::PREFIX . '/v1/appStoreVersionPhasedReleases')
            ->desc('Create App Store Connect phased release')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('data', [], new Assoc(), 'Phased release resource with an optional `phasedReleaseState` attribute and an `appStoreVersion` relationship.')
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

        if (($data['type'] ?? null) !== self::VERSION_TYPE_PHASED) {
            $this->versionConflict($response, self::CODE_TYPE_INVALID, 'The provided entity type is invalid', "The type must be '" . self::VERSION_TYPE_PHASED . "'.", '/data/type');
            return;
        }

        if (($attributes['phasedReleaseState'] ?? self::VERSION_PHASED_INACTIVE) !== self::VERSION_PHASED_INACTIVE) {
            $this->versionConflict($response, self::CODE_ATTRIBUTE_INVALID, 'An attribute value is invalid.', "A phased release can only be created as '" . self::VERSION_PHASED_INACTIVE . "'.", '/data/attributes/phasedReleaseState');
            return;
        }

        $versionId = $this->versionLinkage($relationships, 'appStoreVersion', self::VERSION_TYPE);

        if ($versionId === null) {
            $this->versionConflict($response, self::CODE_RELATIONSHIP_REQUIRED, 'The provided entity is missing a required relationship', "You must provide a value for the relationship 'appStoreVersion' with this request", '/data/relationships/appStoreVersion');
            return;
        }

        $version = $this->version($cache, $versionId);

        if ($version === null || !$this->ownsVersion($cache, $key['issuerId'], $version)) {
            $this->versionConflict($response, self::CODE_RELATIONSHIP_INVALID, 'The provided entity includes a relationship with an invalid value', "The version '{$versionId}' does not exist.", '/data/relationships/appStoreVersion');
            return;
        }

        if ($version[self::INTERNAL]['phased'] !== null) {
            $this->versionConflict($response, self::CODE_RELATIONSHIP_INVALID, 'The provided entity includes a relationship with an invalid value', 'The version already has a phased release.', '/data/relationships/appStoreVersion');
            return;
        }

        if (!\in_array($version['attributes']['appVersionState'], self::VERSION_PREPARABLE, true) || !$this->versionReleased($cache, $version[self::INTERNAL]['appId'])) {
            $this->versionConflict($response, self::VERSION_CODE_STATE, 'The request cannot be fulfilled because of the state of another resource.', 'A phased release is only available for an editable update to a released app.');
            return;
        }

        $phased = [
            'type' => self::VERSION_TYPE_PHASED,
            'id' => $this->uuid(),
            'attributes' => [
                'phasedReleaseState' => self::VERSION_PHASED_INACTIVE,
                'startDate' => null,
                'totalPauseDuration' => 0,
                'currentDayNumber' => null,
            ],
        ];

        $version[self::INTERNAL]['phased'] = $phased;

        $this->saveVersion($cache, $lock, $version);

        $this->respond($response, [
            'data' => $phased,
            'links' => ['self' => $this->link($request)],
        ], Response::STATUS_CODE_CREATED);
    }
}
