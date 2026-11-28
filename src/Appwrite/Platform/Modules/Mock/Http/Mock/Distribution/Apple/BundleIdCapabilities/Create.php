<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\BundleIdCapabilities;

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
        'attributes' => ['capabilityType', 'settings'],
        'relationships' => ['bundleId'],
    ];
    private const string CODE_CAPABILITY_DUPLICATE = 'ENTITY_ERROR.ATTRIBUTE.INVALID.DUPLICATE';
    private const array TYPES = [
        'ICLOUD',
        'IN_APP_PURCHASE',
        'GAME_CENTER',
        'PUSH_NOTIFICATIONS',
        'WALLET',
        'INTER_APP_AUDIO',
        'MAPS',
        'ASSOCIATED_DOMAINS',
        'PERSONAL_VPN',
        'APP_GROUPS',
        'HEALTHKIT',
        'HOMEKIT',
        'WIRELESS_ACCESSORY_CONFIGURATION',
        'APPLE_PAY',
        'DATA_PROTECTION',
        'SIRIKIT',
        'NETWORK_EXTENSIONS',
        'MULTIPATH',
        'HOT_SPOT',
        'NFC_TAG_READING',
        'CLASSKIT',
        'AUTOFILL_CREDENTIAL_PROVIDER',
        'ACCESS_WIFI_INFORMATION',
        'NETWORK_CUSTOM_PROTOCOL',
        'COREMEDIA_HLS_LOW_LATENCY',
        'SYSTEM_EXTENSION_INSTALL',
        'USER_MANAGEMENT',
        'APPLE_ID_AUTH',
    ];

    public static function getName(): string
    {
        return 'createDistributionAppleBundleIdCapability';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath(self::PREFIX . '/v1/bundleIdCapabilities')
            ->desc('Enable App Store Connect bundle ID capability')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('data', [], new Assoc(), 'Bundle ID capability resource with a `capabilityType` attribute, optional `settings` and a `bundleId` relationship.')
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

        $team = $this->provisioning($response, $cache, $key['issuerId']);

        if ($team === null) {
            return;
        }

        if (($data['type'] ?? null) !== self::BUNDLE_TYPE_CAPABILITY) {
            $this->invalid($response, self::CODE_TYPE_INVALID, 'The provided entity type is invalid', "The type must be '" . self::BUNDLE_TYPE_CAPABILITY . "'.", '/data/type');
            return;
        }

        if (!\array_key_exists('capabilityType', $attributes)) {
            $this->invalid($response, self::CODE_ATTRIBUTE_REQUIRED, 'The provided entity is missing a required attribute', "You must provide a value for the attribute 'capabilityType' with this request", '/data/attributes/capabilityType');
            return;
        }

        $type = $attributes['capabilityType'];

        if (!\in_array($type, self::TYPES, true)) {
            $this->invalid($response, self::CODE_ATTRIBUTE_INVALID, 'An attribute value is invalid.', "The attribute 'capabilityType' has an invalid value.", '/data/attributes/capabilityType');
            return;
        }

        if (\array_key_exists('settings', $attributes) && (!\is_array($attributes['settings']) || !\array_is_list($attributes['settings']))) {
            $this->invalid($response, self::CODE_ATTRIBUTE_INVALID, 'An attribute value is invalid.', "The attribute 'settings' has an invalid value.", '/data/attributes/settings');
            return;
        }

        $linkage = $relationships['bundleId']['data'] ?? null;

        if (!\is_array($linkage) || ($linkage['type'] ?? null) !== self::BUNDLE_TYPE || !\is_string($linkage['id'] ?? null) || $linkage['id'] === '') {
            $this->invalid($response, self::CODE_RELATIONSHIP_REQUIRED, 'The provided entity is missing a required relationship', "You must provide a value for the relationship 'bundleId' with this request", '/data/relationships/bundleId');
            return;
        }

        $this->locked($lock, self::LOCK_TEAM . $team['teamId'], function () use ($cache, $team, $linkage, $response, $type, $request): void {
            $bundles = $this->bundles($cache, $team['teamId']);
            $index = \array_search($linkage['id'], \array_column($bundles, 'id'), true);

            if ($index === false) {
                $this->invalid($response, self::CODE_RELATIONSHIP_INVALID, 'The provided entity includes a relationship with an invalid value', "The bundle ID '{$linkage['id']}' does not exist.", '/data/relationships/bundleId');
                return;
            }

            $capabilities = $bundles[$index][self::INTERNAL]['capabilities'];

            if (\in_array($type, \array_column($capabilities, 'capabilityType'), true)) {
                $this->invalid($response, self::CODE_CAPABILITY_DUPLICATE, 'The provided entity includes an attribute with an invalid value', "The capability {$type} is already enabled for this bundle ID.", '/data/attributes/capabilityType');
                return;
            }

            $capability = ['id' => $linkage['id'] . '_' . $type, 'capabilityType' => $type];
            $capabilities[] = $capability;
            $bundles[$index][self::INTERNAL]['capabilities'] = $capabilities;

            $cache->save(self::CACHE_BUNDLE_BUNDLES . $team['teamId'], $bundles, '', self::TTL);

            $this->respond($response, [
                'data' => $this->capabilityResource($capability),
                'links' => ['self' => $this->link($request)],
            ], Response::STATUS_CODE_CREATED);
        });
    }

    private function invalid(Response $response, string $code, string $title, string $detail, string $pointer): void
    {
        $this->error($response, Response::STATUS_CODE_CONFLICT, $code, $title, $detail, ['pointer' => $pointer]);
    }
}
