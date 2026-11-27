<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\AppStoreVersionLocalizations;

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
        'attributes' => ['locale', 'description', 'keywords', 'marketingUrl', 'promotionalText', 'supportUrl', 'whatsNew'],
        'relationships' => ['appStoreVersion'],
    ];

    public static function getName(): string
    {
        return 'createDistributionAppleAppStoreVersionLocalization';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath(self::PREFIX . '/v1/appStoreVersionLocalizations')
            ->desc('Create App Store Connect version localization')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('data', [], new Assoc(), 'Localization resource with a `locale` attribute, optional text attributes and an `appStoreVersion` relationship.')
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

        if (($data['type'] ?? null) !== self::VERSION_TYPE_LOCALIZATION) {
            $this->versionConflict($response, self::CODE_TYPE_INVALID, 'The provided entity type is invalid', "The type must be '" . self::VERSION_TYPE_LOCALIZATION . "'.", '/data/type');
            return;
        }

        $locale = $attributes['locale'] ?? null;

        if (!\is_string($locale) || $locale === '') {
            $this->versionConflict($response, self::CODE_ATTRIBUTE_REQUIRED, 'The provided entity is missing a required attribute', "You must provide a value for the attribute 'locale' with this request", '/data/attributes/locale');
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

        $text = \array_intersect_key($attributes, \array_flip(self::VERSION_LOCALIZATION_ATTRIBUTES));
        $invalid = $this->localizationViolation($cache, $version, $text);

        if ($invalid !== null) {
            [$code, $detail, $pointer] = $invalid;
            $this->versionConflict($response, $code, $code === self::VERSION_CODE_STATE ? 'The request cannot be fulfilled because of the state of another resource.' : 'An attribute value is invalid.', $detail, $pointer);
            return;
        }

        foreach ($version[self::INTERNAL]['localizations'] as $existing) {
            if ($existing['attributes']['locale'] === $locale) {
                $this->versionConflict($response, self::CODE_ATTRIBUTE_INVALID, 'An attribute value is invalid.', "A localization for the locale '{$locale}' already exists.", '/data/attributes/locale');
                return;
            }
        }

        $localization = [
            'id' => $this->uuid(),
            'attributes' => [
                'description' => null,
                'locale' => $locale,
                'keywords' => null,
                'marketingUrl' => null,
                'promotionalText' => null,
                'supportUrl' => null,
                'whatsNew' => null,
                ...$text,
            ],
        ];

        $version[self::INTERNAL]['localizations'][] = $localization;

        $this->saveVersion($cache, $lock, $version);

        $this->respond($response, [
            'data' => $this->versionLocalizationResource($localization),
            'links' => ['self' => $this->link($request)],
        ], Response::STATUS_CODE_CREATED);
    }

    /**
     * @param array<string, mixed> $version
     * @param array<string, mixed> $text
     * @return array{string, string, string}|null
     */
    private function localizationViolation(Cache $cache, array $version, array $text): ?array
    {
        foreach ($text as $name => $value) {
            if ($value !== null && !\is_string($value)) {
                return [self::CODE_ATTRIBUTE_INVALID, "The attribute '{$name}' has an invalid value.", '/data/attributes/' . $name];
            }
        }

        if (\array_diff(\array_keys($text), ['promotionalText']) !== [] && !\in_array($version['attributes']['appVersionState'], self::VERSION_PREPARABLE, true)) {
            return [self::VERSION_CODE_STATE, "The version is {$version['attributes']['appVersionState']} and its localizations cannot be edited.", ''];
        }

        if (($text['whatsNew'] ?? null) !== null && !$this->versionReleased($cache, $version[self::INTERNAL]['appId'])) {
            return [self::CODE_ATTRIBUTE_INVALID, "The attribute 'whatsNew' cannot be set on the first version of an app.", '/data/attributes/whatsNew'];
        }

        return null;
    }
}
