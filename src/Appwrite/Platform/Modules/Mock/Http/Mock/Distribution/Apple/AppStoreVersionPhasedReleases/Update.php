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
use Utopia\Validator\Text;

class Update extends Base
{
    private const array ALLOWED_KEYS = [
        'data' => ['type', 'id', 'attributes'],
        'attributes' => ['phasedReleaseState'],
    ];
    private const array TRANSITIONS = [
        self::VERSION_PHASED_ACTIVE => [self::VERSION_PHASED_PAUSED, self::VERSION_PHASED_COMPLETE],
        self::VERSION_PHASED_PAUSED => [self::VERSION_PHASED_ACTIVE, self::VERSION_PHASED_COMPLETE],
    ];

    public static function getName(): string
    {
        return 'updateDistributionAppleAppStoreVersionPhasedRelease';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath(self::PREFIX . '/v1/appStoreVersionPhasedReleases/:phasedReleaseId')
            ->desc('Update App Store Connect phased release')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('phasedReleaseId', '', new Text(64), 'Phased release ID.')
            ->param('data', [], new Assoc(), 'Phased release resource with the new `phasedReleaseState`: PAUSED, ACTIVE or COMPLETE.')
            ->inject('request')
            ->inject('response')
            ->inject('cache')
            ->inject('lock')
            ->callback($this->action(...));
    }

    /**
     * @param array<string, mixed> $data
     */
    public function action(string $phasedReleaseId, array $data, Request $request, Response $response, Cache $cache, Lock $lock): void
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

        $version = $this->versionOfPhasedRelease($cache, $phasedReleaseId);

        if ($version === null || !$this->ownsVersion($cache, $key['issuerId'], $version)) {
            $this->notFound($response, self::VERSION_TYPE_PHASED, $phasedReleaseId);
            return;
        }

        if (($data['type'] ?? null) !== self::VERSION_TYPE_PHASED || ($data['id'] ?? null) !== $phasedReleaseId) {
            $this->versionConflict($response, self::CODE_TYPE_INVALID, 'The provided entity type is invalid', 'The type and id must match the phased release being updated.', '/data');
            return;
        }

        $phased = $version[self::INTERNAL]['phased'];
        $current = $phased['attributes']['phasedReleaseState'];
        $target = $attributes['phasedReleaseState'] ?? null;

        if (!\in_array($target, [self::VERSION_PHASED_ACTIVE, self::VERSION_PHASED_PAUSED, self::VERSION_PHASED_COMPLETE], true)) {
            $this->versionConflict($response, self::CODE_ATTRIBUTE_INVALID, 'An attribute value is invalid.', "The attribute 'phasedReleaseState' has an invalid value.", '/data/attributes/phasedReleaseState');
            return;
        }

        if ($target !== $current && !\in_array($target, self::TRANSITIONS[$current] ?? [], true)) {
            $this->versionConflict($response, self::VERSION_CODE_STATE, 'The request cannot be fulfilled because of the state of another resource.', "The phased release is {$current} and cannot change to {$target}.");
            return;
        }

        $phased['attributes']['phasedReleaseState'] = $target;

        if ($target === self::VERSION_PHASED_COMPLETE) {
            $phased['attributes']['currentDayNumber'] = 7;
        }

        $version[self::INTERNAL]['phased'] = $phased;

        $this->saveVersion($cache, $lock, $version);

        $this->respond($response, [
            'data' => $phased,
            'links' => ['self' => $this->link($request)],
        ]);
    }
}
