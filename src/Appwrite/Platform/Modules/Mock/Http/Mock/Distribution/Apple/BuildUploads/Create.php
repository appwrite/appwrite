<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\BuildUploads;

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
    private const string TYPE = 'buildUploads';
    private const string CODE_DUPLICATE = 'ENTITY_ERROR.ATTRIBUTE.INVALID.DUPLICATE';
    private const array PLATFORMS = ['IOS', 'MAC_OS', 'TV_OS', 'VISION_OS'];
    private const array ALLOWED_KEYS = [
        'data' => ['type', 'attributes', 'relationships'],
        'attributes' => ['cfBundleShortVersionString', 'cfBundleVersion', 'platform'],
        'relationships' => ['app'],
    ];

    public static function getName(): string
    {
        return 'createDistributionAppleBuildUpload';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath(self::PREFIX . '/v1/buildUploads')
            ->desc('Create App Store Connect build upload')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('data', [], new Assoc(), 'Build upload resource with `cfBundleShortVersionString`, `cfBundleVersion` and `platform` attributes and an `app` relationship.')
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

        if (($data['type'] ?? null) !== self::TYPE) {
            $this->invalid($response, self::CODE_TYPE_INVALID, 'The provided entity type is invalid', "The type must be '" . self::TYPE . "'.", '/data/type');
            return;
        }

        foreach (self::ALLOWED_KEYS['attributes'] as $name) {
            if (!\is_string($attributes[$name] ?? null) || \trim($attributes[$name]) === '') {
                $this->invalid($response, self::CODE_ATTRIBUTE_REQUIRED, 'The provided entity is missing a required attribute', "You must provide a value for the attribute '{$name}' with this request", '/data/attributes/' . $name);
                return;
            }
        }

        if (!\in_array($attributes['platform'], self::PLATFORMS, true)) {
            $this->invalid($response, self::CODE_ATTRIBUTE_INVALID, 'An attribute value is invalid.', "The attribute 'platform' has an invalid value.", '/data/attributes/platform');
            return;
        }

        $app = $relationships['app']['data'] ?? null;

        if (!\is_array($app) || ($app['type'] ?? null) !== 'apps' || !\is_string($app['id'] ?? null) || $app['id'] === '') {
            $this->invalid($response, self::CODE_RELATIONSHIP_REQUIRED, 'The provided entity is missing a required relationship', "You must provide a value for the relationship 'app' with this request", '/data/relationships/app');
            return;
        }

        if (!$this->owns($cache, $key['issuerId'], $app['id'])) {
            $this->invalid($response, self::CODE_RELATIONSHIP_INVALID, 'The provided entity includes a relationship with an invalid value', "The app '{$app['id']}' does not exist.", '/data/relationships/app');
            return;
        }

        $number = \implode(':', [$app['id'], $attributes['platform'], $attributes['cfBundleShortVersionString'], $attributes['cfBundleVersion']]);

        $upload = [
            'id' => $this->uuid(),
            'appId' => $app['id'],
            'cfBundleShortVersionString' => $attributes['cfBundleShortVersionString'],
            'cfBundleVersion' => $attributes['cfBundleVersion'],
            'platform' => $attributes['platform'],
            'createdDate' => (new \DateTimeImmutable())->format(\DateTimeInterface::RFC3339_EXTENDED),
            'uploadedDate' => null,
            'state' => self::UPLOAD_STATE_AWAITING,
            'errors' => [],
            'number' => $number,
            'buildId' => null,
            'fileId' => null,
        ];

        $created = $this->locked($lock, self::CACHE_UPLOAD_NUMBERS . $number, function () use ($cache, $number, $upload): bool {
            if ($this->duplicate($cache, $number, $upload['appId'], $upload['cfBundleShortVersionString'], $upload['cfBundleVersion'])) {
                return false;
            }

            $cache->save(self::CACHE_UPLOAD_BUILDS . $upload['id'], $upload, '', self::TTL);
            $cache->save(self::CACHE_UPLOAD_NUMBERS . $number, $upload['id'], '', self::TTL);

            return true;
        });

        if (!$created) {
            $this->invalid(
                $response,
                self::CODE_DUPLICATE,
                'The provided entity includes an attribute with a value that has already been used',
                "The bundle version '{$attributes['cfBundleVersion']}' has already been used for version '{$attributes['cfBundleShortVersionString']}'.",
                '/data/attributes/cfBundleVersion',
            );
            return;
        }

        $this->respond($response, [
            'data' => $this->buildUploadResource($upload),
            'links' => ['self' => $this->link($request) . '/' . $upload['id']],
        ], Response::STATUS_CODE_CREATED);
    }

    private function duplicate(Cache $cache, string $number, string $appId, string $marketingVersion, string $version): bool
    {
        $uploadId = $cache->load(self::CACHE_UPLOAD_NUMBERS . $number, self::TTL);

        if (\is_string($uploadId) && \is_array($cache->load(self::CACHE_UPLOAD_BUILDS . $uploadId, self::TTL))) {
            return true;
        }

        $builds = $cache->load(self::CACHE_BUILDS . $appId, self::TTL);

        foreach (\is_array($builds) ? $builds : [] as $build) {
            if ($build['attributes']['version'] === $version && ($build[self::INTERNAL]['marketingVersion'] ?? null) === $marketingVersion) {
                return true;
            }
        }

        return false;
    }

    private function invalid(Response $response, string $code, string $title, string $detail, string $pointer): void
    {
        $this->error($response, Response::STATUS_CODE_CONFLICT, $code, $title, $detail, ['pointer' => $pointer]);
    }
}
