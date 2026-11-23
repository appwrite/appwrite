<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\Builds;

use Appwrite\Extend\Exception;
use Appwrite\Locking\Lock;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\Base;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Cache\Cache;
use Utopia\Client\Client;
use Utopia\System\System;
use Utopia\Validator\Assoc;
use Utopia\Validator\Text;

class Update extends Base
{
    private const string TYPE = 'builds';
    private const array ALLOWED_KEYS = [
        'data' => ['type', 'id', 'attributes'],
        'attributes' => ['usesNonExemptEncryption', 'expired'],
    ];

    public static function getName(): string
    {
        return 'updateDistributionAppleBuild';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath(self::PREFIX . '/v1/builds/:buildId')
            ->desc('Update App Store Connect build')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('buildId', '', new Text(64), 'Build ID.')
            ->param('data', [], new Assoc(), 'Build resource with optional `usesNonExemptEncryption` and `expired` attributes.')
            ->inject('request')
            ->inject('response')
            ->inject('cache')
            ->inject('lock')
            ->inject('clientForWebhooks')
            ->callback($this->action(...));
    }

    /**
     * @param array<string, mixed> $data
     */
    public function action(string $buildId, array $data, Request $request, Response $response, Cache $cache, Lock $lock, Client $clientForWebhooks): void
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

        $owned = $this->betaBuild($cache, $key['issuerId'], $buildId);

        if ($owned === null) {
            $this->notFound($response, self::TYPE, $buildId);
            return;
        }

        if (($data['type'] ?? null) !== self::TYPE) {
            $this->invalid($response, self::CODE_TYPE_INVALID, 'The provided entity type is invalid', "The type must be '" . self::TYPE . "'.", '/data/type');
            return;
        }

        if (($data['id'] ?? null) !== $buildId) {
            $this->invalid($response, self::CODE_ATTRIBUTE_INVALID, 'The provided entity id is invalid', "The id must be '{$buildId}'.", '/data/id');
            return;
        }

        foreach ($attributes as $name => $value) {
            if ($value !== null && !\is_bool($value)) {
                $this->invalid($response, self::CODE_ATTRIBUTE_INVALID, 'An attribute value is invalid.', "The attribute '{$name}' must be a boolean.", '/data/attributes/' . $name);
                return;
            }
        }

        $build = $owned['build'];

        if (($attributes['expired'] ?? null) === false && ($build['attributes']['expired'] ?? false) === true) {
            $this->invalid($response, self::CODE_ATTRIBUTE_INVALID, 'An attribute value is invalid.', 'An expired build cannot be made available again.', '/data/attributes/expired');
            return;
        }

        $before = $this->betaDetail($cache, $lock, $clientForWebhooks, $owned['appId'], $build);

        foreach ($attributes as $name => $value) {
            if ($value !== null) {
                $build['attributes'][$name] = $value;
            }
        }

        $this->saveBuild($cache, $lock, $owned['appId'], $build);
        $this->betaDeliver($cache, $lock, $clientForWebhooks, $owned['appId'], $before, $this->betaDetail($cache, $lock, $clientForWebhooks, $owned['appId'], $build));

        unset($build[self::INTERNAL]);

        $this->respond($response, [
            'data' => $build,
            'links' => ['self' => $this->link($request)],
        ]);
    }

    private function invalid(Response $response, string $code, string $title, string $detail, string $pointer): void
    {
        $this->error($response, Response::STATUS_CODE_CONFLICT, $code, $title, $detail, ['pointer' => $pointer]);
    }
}
