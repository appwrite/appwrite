<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\BetaBuildLocalizations;

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
    private const string TYPE = 'betaBuildLocalizations';
    private const array ALLOWED_KEYS = [
        'data' => ['type', 'attributes', 'relationships'],
        'attributes' => ['locale', 'whatsNew'],
        'relationships' => ['build'],
    ];

    public static function getName(): string
    {
        return 'createDistributionAppleBetaBuildLocalization';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath(self::PREFIX . '/v1/betaBuildLocalizations')
            ->desc('Create App Store Connect beta build localization')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('data', [], new Assoc(), 'Beta build localization resource with a `locale` attribute, an optional `whatsNew` attribute and a `build` relationship.')
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

        $locale = $attributes['locale'] ?? null;

        if (!\is_string($locale) || \trim($locale) === '') {
            $this->invalid($response, self::CODE_ATTRIBUTE_REQUIRED, 'The provided entity is missing a required attribute', "You must provide a value for the attribute 'locale' with this request", '/data/attributes/locale');
            return;
        }

        $whatsNew = $attributes['whatsNew'] ?? null;

        if ($whatsNew !== null && !\is_string($whatsNew)) {
            $this->invalid($response, self::CODE_ATTRIBUTE_INVALID, 'An attribute value is invalid.', "The attribute 'whatsNew' must be a string.", '/data/attributes/whatsNew');
            return;
        }

        $build = $relationships['build']['data'] ?? null;

        if (!\is_array($build) || ($build['type'] ?? null) !== 'builds' || !\is_string($build['id'] ?? null) || $build['id'] === '') {
            $this->invalid($response, self::CODE_RELATIONSHIP_REQUIRED, 'The provided entity is missing a required relationship', "You must provide a value for the relationship 'build' with this request", '/data/relationships/build');
            return;
        }

        if ($this->betaBuild($cache, $key['issuerId'], $build['id']) === null) {
            $this->invalid($response, self::CODE_RELATIONSHIP_INVALID, 'The provided entity includes a relationship with an invalid value', "The build '{$build['id']}' does not exist.", '/data/relationships/build');
            return;
        }

        foreach ($this->betaLocalizations($cache, $build['id']) as $existing) {
            if ($existing['attributes']['locale'] === $locale) {
                $this->invalid($response, self::CODE_ATTRIBUTE_INVALID, 'An attribute value is invalid.', "A localization for the locale '{$locale}' already exists for this build.", '/data/attributes/locale');
                return;
            }
        }

        $localization = [
            'type' => self::TYPE,
            'id' => $this->uuid(),
            'attributes' => [
                'locale' => $locale,
                'whatsNew' => $whatsNew,
            ],
        ];

        $this->saveBetaLocalization($cache, $lock, $build['id'], $localization);

        $this->respond($response, [
            'data' => $localization,
            'links' => ['self' => $this->link($request)],
        ], Response::STATUS_CODE_CREATED);
    }

    private function invalid(Response $response, string $code, string $title, string $detail, string $pointer): void
    {
        $this->error($response, Response::STATUS_CODE_CONFLICT, $code, $title, $detail, ['pointer' => $pointer]);
    }
}
