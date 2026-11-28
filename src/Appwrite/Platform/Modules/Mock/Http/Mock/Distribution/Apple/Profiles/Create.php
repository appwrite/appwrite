<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\Profiles;

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
    private const string TYPE = 'profiles';
    private const array ALLOWED_KEYS = [
        'data' => ['type', 'attributes', 'relationships'],
        'attributes' => ['name', 'profileType'],
        'relationships' => ['bundleId', 'certificates', 'devices'],
    ];
    private const array REQUIRED = ['name', 'profileType'];
    private const array TYPES = [
        'IOS_APP_DEVELOPMENT',
        'IOS_APP_STORE',
        'IOS_APP_ADHOC',
        'IOS_APP_INHOUSE',
        'MAC_APP_DEVELOPMENT',
        'MAC_APP_STORE',
        'MAC_APP_DIRECT',
        'TVOS_APP_DEVELOPMENT',
        'TVOS_APP_STORE',
        'TVOS_APP_ADHOC',
        'TVOS_APP_INHOUSE',
        'MAC_CATALYST_APP_DEVELOPMENT',
        'MAC_CATALYST_APP_STORE',
        'MAC_CATALYST_APP_DIRECT',
    ];
    private const string KIND_DEVELOPMENT = '_DEVELOPMENT';
    private const string KIND_ADHOC = '_ADHOC';
    private const string KIND_INHOUSE = '_INHOUSE';
    private const string CAPABILITY_PUSH = 'PUSH_NOTIFICATIONS';
    private const string PLIST_DATE = 'Y-m-d\TH:i:s\Z';
    private const int DAYS = 365;

    public static function getName(): string
    {
        return 'createDistributionAppleProfile';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath(self::PREFIX . '/v1/profiles')
            ->desc('Create App Store Connect provisioning profile')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('data', [], new Assoc(), 'Profile resource with `name` and `profileType` attributes, `bundleId` and `certificates` relationships and optional `devices`.')
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

        if (($data['type'] ?? null) !== self::TYPE) {
            $this->invalid($response, self::CODE_TYPE_INVALID, 'The provided entity type is invalid', "The type must be '" . self::TYPE . "'.", '/data/type');
            return;
        }

        foreach (self::REQUIRED as $name) {
            if (!\array_key_exists($name, $attributes)) {
                $this->invalid($response, self::CODE_ATTRIBUTE_REQUIRED, 'The provided entity is missing a required attribute', "You must provide a value for the attribute '{$name}' with this request", '/data/attributes/' . $name);
                return;
            }
        }

        if (!\is_string($attributes['name']) || \trim($attributes['name']) === '') {
            $this->invalid($response, self::CODE_ATTRIBUTE_INVALID, 'An attribute value is invalid.', "The attribute 'name' has an invalid value.", '/data/attributes/name');
            return;
        }

        if (!\in_array($attributes['profileType'], self::TYPES, true)) {
            $this->invalid($response, self::CODE_ATTRIBUTE_INVALID, 'An attribute value is invalid.', "The attribute 'profileType' has an invalid value.", '/data/attributes/profileType');
            return;
        }

        $bundleLinkage = $relationships['bundleId']['data'] ?? null;

        if (!\is_array($bundleLinkage) || ($bundleLinkage['type'] ?? null) !== self::BUNDLE_TYPE || !\is_string($bundleLinkage['id'] ?? null)) {
            $this->required($response, 'bundleId');
            return;
        }

        $this->locked($lock, self::LOCK_TEAM . $team['teamId'], function () use ($cache, $lock, $team, $bundleLinkage, $response, $relationships, $attributes, $request): void {
            $bundle = $this->bundle($cache, $team['teamId'], $bundleLinkage['id']);

            if ($bundle === null) {
                $this->unrelated($response, 'bundleId', "The bundle ID '{$bundleLinkage['id']}' does not exist.");
                return;
            }

            $certificateIds = $this->linkages($relationships['certificates']['data'] ?? null, 'certificates');

            if ($certificateIds === null || $certificateIds === []) {
                $this->required($response, 'certificates');
                return;
            }

            $certificates = [];

            foreach ($this->certificates($cache, $team['teamId']) as $certificate) {
                $certificates[$certificate['id']] = $certificate;
            }

            $missing = \array_diff($certificateIds, \array_keys($certificates));

            if ($missing !== []) {
                $this->unrelated($response, 'certificates', "The certificate '" . \reset($missing) . "' does not exist.");
                return;
            }

            $deviceIds = $this->linkages($relationships['devices']['data'] ?? [], 'devices');

            if ($deviceIds === null) {
                $this->unrelated($response, 'devices', 'The devices relationship is invalid.');
                return;
            }

            $created = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $profile = [
                'name' => $attributes['name'],
                'profileType' => $attributes['profileType'],
                'uuid' => $this->uuid(),
                'created' => $created,
                'expires' => $created->modify('+' . self::DAYS . ' days'),
            ];

            $envelope = $this->envelope(
                $this->plist($profile, $team['teamId'], $bundle, \array_map(fn (string $certificateId): string => $certificates[$certificateId]['attributes']['certificateContent'], $certificateIds), $deviceIds),
                $this->authority($cache, $lock),
            );

            if ($envelope === null) {
                throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Mock provisioning profile could not be signed');
            }

            $record = [
                'type' => self::TYPE,
                'id' => $this->resourceId(),
                'attributes' => [
                    'name' => $profile['name'],
                    'platform' => 'IOS',
                    'profileType' => $profile['profileType'],
                    'profileState' => self::PROFILE_STATE_ACTIVE,
                    'profileContent' => \base64_encode($envelope),
                    'uuid' => $profile['uuid'],
                    'createdDate' => $created->format(self::CERTIFICATE_DATE),
                    'expirationDate' => $profile['expires']->format(self::CERTIFICATE_DATE),
                ],
                self::INTERNAL => [
                    'bundleId' => $bundle['id'],
                    'certificateIds' => \array_values($certificateIds),
                ],
            ];

            $profiles = $this->profiles($cache, $team['teamId']);
            $profiles[] = $record;
            $cache->save(self::CACHE_PROFILE_PROFILES . $team['teamId'], $profiles, '', self::TTL);

            $this->respond($response, [
                'data' => $this->profileResource($record),
                'links' => ['self' => $this->link($request)],
            ], Response::STATUS_CODE_CREATED);
        });
    }

    /**
     * @return list<string>|null
     */
    private function linkages(mixed $data, string $type): ?array
    {
        if (!\is_array($data) || !\array_is_list($data)) {
            return null;
        }

        $ids = [];

        foreach ($data as $linkage) {
            if (!\is_array($linkage) || ($linkage['type'] ?? null) !== $type || !\is_string($linkage['id'] ?? null) || $linkage['id'] === '') {
                return null;
            }

            $ids[] = $linkage['id'];
        }

        return \array_values(\array_unique($ids));
    }

    /**
     * @param array{name: string, profileType: string, uuid: string, created: \DateTimeImmutable, expires: \DateTimeImmutable} $profile
     * @param array<string, mixed> $bundle
     * @param list<string> $certificates
     * @param list<string> $deviceIds
     */
    private function plist(array $profile, string $teamId, array $bundle, array $certificates, array $deviceIds): string
    {
        $type = $profile['profileType'];
        $development = \str_ends_with($type, self::KIND_DEVELOPMENT);
        $push = \in_array(self::CAPABILITY_PUSH, \array_column($bundle[self::INTERNAL]['capabilities'], 'capabilityType'), true);

        $entitlements = $this->entry('application-identifier', $this->text($teamId . '.' . $bundle['attributes']['identifier']))
            . $this->entry('com.apple.developer.team-identifier', $this->text($teamId))
            . $this->entry('keychain-access-groups', '<array>' . $this->text($teamId . '.*') . '</array>')
            . $this->entry('get-task-allow', $development ? '<true/>' : '<false/>')
            . ($push ? $this->entry('aps-environment', $this->text($development ? 'development' : 'production')) : '');

        $data = \implode('', \array_map(fn (string $certificate): string => '<data>' . $certificate . '</data>', $certificates));

        $devices = match (true) {
            $development, \str_ends_with($type, self::KIND_ADHOC) => $this->entry('ProvisionedDevices', '<array>' . \implode('', \array_map($this->text(...), $deviceIds)) . '</array>'),
            \str_ends_with($type, self::KIND_INHOUSE) => $this->entry('ProvisionsAllDevices', '<true/>'),
            default => '',
        };

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">' . "\n"
            . '<plist version="1.0"><dict>'
            . $this->entry('AppIDName', $this->text($bundle['attributes']['name']))
            . $this->entry('ApplicationIdentifierPrefix', '<array>' . $this->text($teamId) . '</array>')
            . $this->entry('CreationDate', '<date>' . $profile['created']->format(self::PLIST_DATE) . '</date>')
            . $this->entry('Platform', '<array>' . $this->text('iOS') . '</array>')
            . $this->entry('DeveloperCertificates', '<array>' . $data . '</array>')
            . $this->entry('Entitlements', '<dict>' . $entitlements . '</dict>')
            . $this->entry('ExpirationDate', '<date>' . $profile['expires']->format(self::PLIST_DATE) . '</date>')
            . $this->entry('Name', $this->text($profile['name']))
            . $devices
            . $this->entry('TeamIdentifier', '<array>' . $this->text($teamId) . '</array>')
            . $this->entry('TeamName', $this->text($teamId))
            . $this->entry('TimeToLive', '<integer>' . self::DAYS . '</integer>')
            . $this->entry('UUID', $this->text($profile['uuid']))
            . $this->entry('Version', '<integer>1</integer>')
            . '</dict></plist>' . "\n";
    }

    private function entry(string $key, string $value): string
    {
        return '<key>' . \htmlspecialchars($key, ENT_XML1) . '</key>' . $value;
    }

    private function text(string $value): string
    {
        return '<string>' . \htmlspecialchars($value, ENT_XML1) . '</string>';
    }

    /**
     * @param array{key: string, certificate: string} $authority
     */
    private function envelope(string $plist, array $authority): ?string
    {
        $input = \tempnam(\sys_get_temp_dir(), 'plist');
        $output = \tempnam(\sys_get_temp_dir(), 'profile');

        try {
            if ($input === false || $output === false || \file_put_contents($input, $plist) !== \strlen($plist)) {
                return null;
            }

            if (!\openssl_cms_sign($input, $output, $authority['certificate'], $authority['key'], null, OPENSSL_CMS_BINARY, OPENSSL_ENCODING_DER)) {
                return null;
            }

            $envelope = \file_get_contents($output);

            return $envelope === false ? null : $envelope;
        } finally {
            foreach ([$input, $output] as $path) {
                if (\is_string($path) && \is_file($path)) {
                    \unlink($path);
                }
            }
        }
    }

    private function required(Response $response, string $relationship): void
    {
        $this->invalid($response, self::CODE_RELATIONSHIP_REQUIRED, 'The provided entity is missing a required relationship', "You must provide a value for the relationship '{$relationship}' with this request", '/data/relationships/' . $relationship);
    }

    private function unrelated(Response $response, string $relationship, string $detail): void
    {
        $this->invalid($response, self::CODE_RELATIONSHIP_INVALID, 'The provided entity includes a relationship with an invalid value', $detail, '/data/relationships/' . $relationship);
    }

    private function invalid(Response $response, string $code, string $title, string $detail, string $pointer): void
    {
        $this->error($response, Response::STATUS_CODE_CONFLICT, $code, $title, $detail, ['pointer' => $pointer]);
    }
}
