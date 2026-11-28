<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\BundleIds;

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
        'data' => ['type', 'attributes'],
        'attributes' => ['identifier', 'name', 'platform', 'seedId'],
    ];
    private const array REQUIRED = ['identifier', 'name', 'platform'];
    private const array PLATFORMS = ['IOS', 'MAC_OS', 'UNIVERSAL'];
    private const string WILDCARD = '*';
    private const array SEPARATORS = ['.', '-'];

    public static function getName(): string
    {
        return 'createDistributionAppleBundleId';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath(self::PREFIX . '/v1/bundleIds')
            ->desc('Create App Store Connect bundle ID')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('data', [], new Assoc(), 'Bundle ID resource with `identifier`, `name` and `platform` attributes and an optional `seedId`.')
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

        $team = $this->provisioning($response, $cache, $key['issuerId']);

        if ($team === null) {
            return;
        }

        if (($data['type'] ?? null) !== self::BUNDLE_TYPE) {
            $this->invalid($response, self::CODE_TYPE_INVALID, 'The provided entity type is invalid', "The type must be '" . self::BUNDLE_TYPE . "'.", '/data/type');
            return;
        }

        foreach (self::REQUIRED as $name) {
            if (!\array_key_exists($name, $attributes)) {
                $this->invalid($response, self::CODE_ATTRIBUTE_REQUIRED, 'The provided entity is missing a required attribute', "You must provide a value for the attribute '{$name}' with this request", '/data/attributes/' . $name);
                return;
            }
        }

        $invalid = $this->violation($attributes, $team['teamId']);

        if ($invalid !== null) {
            $this->invalid($response, self::CODE_ATTRIBUTE_INVALID, 'An attribute value is invalid.', "The attribute '{$invalid}' has an invalid value.", '/data/attributes/' . $invalid);
            return;
        }

        $this->locked($lock, self::LOCK_TEAM . $team['teamId'], function () use ($cache, $team, $attributes, $response, $request): void {
            $bundles = $this->bundles($cache, $team['teamId']);

            foreach ($bundles as $bundle) {
                if ($bundle['attributes']['identifier'] === $attributes['identifier']) {
                    $this->invalid($response, self::CODE_ATTRIBUTE_INVALID, 'An attribute value is invalid.', "An App ID with Identifier '{$attributes['identifier']}' is not available. Please enter a different string.", '/data/attributes/identifier');
                    return;
                }
            }

            $bundle = [
                'type' => self::BUNDLE_TYPE,
                'id' => $this->resourceId(),
                'attributes' => [
                    'name' => $attributes['name'],
                    'platform' => $attributes['platform'],
                    'identifier' => $attributes['identifier'],
                    'seedId' => $team['teamId'],
                ],
                self::INTERNAL => ['capabilities' => []],
            ];

            $bundles[] = $bundle;
            $cache->save(self::CACHE_BUNDLE_BUNDLES . $team['teamId'], $bundles, '', self::TTL);

            $this->respond($response, [
                'data' => $this->bundleResource($bundle),
                'links' => ['self' => $this->link($request)],
            ], Response::STATUS_CODE_CREATED);
        });
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function violation(array $attributes, string $teamId): ?string
    {
        $identifier = $attributes['identifier'];

        if (!\is_string($identifier) || !$this->identifier($identifier)) {
            return 'identifier';
        }

        if (!\is_string($attributes['name']) || \trim($attributes['name']) === '') {
            return 'name';
        }

        if (!\in_array($attributes['platform'], self::PLATFORMS, true)) {
            return 'platform';
        }

        if (\array_key_exists('seedId', $attributes) && $attributes['seedId'] !== $teamId) {
            return 'seedId';
        }

        return null;
    }

    private function identifier(string $identifier): bool
    {
        $stem = \str_ends_with($identifier, '.' . self::WILDCARD) ? \substr($identifier, 0, -2) : $identifier;
        $characters = \str_replace(self::SEPARATORS, '', $stem);

        return $characters !== '' && \ctype_alnum($characters) && !\str_starts_with($stem, '.') && !\str_ends_with($stem, '.');
    }

    private function invalid(Response $response, string $code, string $title, string $detail, string $pointer): void
    {
        $this->error($response, Response::STATUS_CODE_CONFLICT, $code, $title, $detail, ['pointer' => $pointer]);
    }
}
