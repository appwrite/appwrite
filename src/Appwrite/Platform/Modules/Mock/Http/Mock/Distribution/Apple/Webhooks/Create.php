<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\Webhooks;

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
    private const string TYPE = 'webhooks';
    private const array ALLOWED_KEYS = [
        'data' => ['type', 'attributes', 'relationships'],
        'attributes' => ['enabled', 'eventTypes', 'name', 'secret', 'url'],
        'relationships' => ['app'],
    ];
    private const array REQUIRED = ['enabled', 'eventTypes', 'name', 'secret', 'url'];
    private const array SCHEMES = ['http', 'https'];
    private const array EVENT_TYPES = [
        'ALTERNATIVE_DISTRIBUTION_PACKAGE_AVAILABLE_UPDATED',
        'ALTERNATIVE_DISTRIBUTION_PACKAGE_VERSION_CREATED',
        'ALTERNATIVE_DISTRIBUTION_TERRITORY_AVAILABILITY_UPDATED',
        'APP_STORE_VERSION_APP_VERSION_STATE_UPDATED',
        'BACKGROUND_ASSET_VERSION_APP_STORE_RELEASE_STATE_UPDATED',
        'BACKGROUND_ASSET_VERSION_EXTERNAL_BETA_RELEASE_STATE_UPDATED',
        'BACKGROUND_ASSET_VERSION_INTERNAL_BETA_RELEASE_CREATED',
        'BACKGROUND_ASSET_VERSION_STATE_UPDATED',
        'BETA_FEEDBACK_CRASH_SUBMISSION_CREATED',
        'BETA_FEEDBACK_SCREENSHOT_SUBMISSION_CREATED',
        'BUILD_BETA_DETAIL_EXTERNAL_BUILD_STATE_UPDATED',
        'BUILD_UPLOAD_STATE_UPDATED',
    ];

    public static function getName(): string
    {
        return 'createDistributionAppleWebhook';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath(self::PREFIX . '/v1/webhooks')
            ->desc('Create App Store Connect webhook')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('data', [], new Assoc(), 'Webhook resource with `name`, `url`, `secret`, `eventTypes` and `enabled` attributes and an `app` relationship.')
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

        foreach (self::REQUIRED as $name) {
            if (!\array_key_exists($name, $attributes)) {
                $this->invalid($response, self::CODE_ATTRIBUTE_REQUIRED, 'The provided entity is missing a required attribute', "You must provide a value for the attribute '{$name}' with this request", '/data/attributes/' . $name);
                return;
            }
        }

        $invalid = $this->violation($attributes);

        if ($invalid !== null) {
            $this->invalid($response, self::CODE_ATTRIBUTE_INVALID, 'An attribute value is invalid.', "The attribute '{$invalid}' has an invalid value.", '/data/attributes/' . $invalid);
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

        $webhook = [
            'id' => $this->uuid(),
            'appId' => $app['id'],
            'enabled' => $attributes['enabled'],
            'eventTypes' => \array_values(\array_unique($attributes['eventTypes'])),
            'name' => $attributes['name'],
            'url' => $attributes['url'],
            'secret' => $attributes['secret'],
        ];

        $this->saveWebhook($cache, $lock, $webhook);

        $this->respond($response, [
            'data' => $this->webhookResource($webhook),
            'links' => ['self' => $this->link($request)],
        ], Response::STATUS_CODE_CREATED);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function violation(array $attributes): ?string
    {
        if (!\is_bool($attributes['enabled'])) {
            return 'enabled';
        }

        $eventTypes = $attributes['eventTypes'];

        if (!\is_array($eventTypes) || $eventTypes === [] || !\array_is_list($eventTypes)) {
            return 'eventTypes';
        }

        foreach ($eventTypes as $eventType) {
            if (!\in_array($eventType, self::EVENT_TYPES, true)) {
                return 'eventTypes';
            }
        }

        foreach (['name', 'secret'] as $name) {
            if (!\is_string($attributes[$name]) || \trim($attributes[$name]) === '') {
                return $name;
            }
        }

        $url = $attributes['url'];
        $scheme = \is_string($url) ? \parse_url($url, PHP_URL_SCHEME) : null;

        if (\filter_var($url, FILTER_VALIDATE_URL) === false || !\in_array($scheme, self::SCHEMES, true)) {
            return 'url';
        }

        return null;
    }

    private function invalid(Response $response, string $code, string $title, string $detail, string $pointer): void
    {
        $this->error($response, Response::STATUS_CODE_CONFLICT, $code, $title, $detail, ['pointer' => $pointer]);
    }
}
