<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\AppInfoLocalizations;

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
        'attributes' => ['locale', 'name', 'subtitle', 'privacyPolicyUrl', 'privacyChoicesUrl', 'privacyPolicyText'],
        'relationships' => ['appInfo'],
    ];

    public static function getName(): string
    {
        return 'createDistributionAppleAppInfoLocalization';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath(self::PREFIX . '/v1/appInfoLocalizations')
            ->desc('Create App Store Connect app information localization')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('data', [], new Assoc(), 'Localization resource with a `locale` attribute, optional text attributes and an `appInfo` relationship.')
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

        if (($data['type'] ?? null) !== self::INFO_TYPE_LOCALIZATION) {
            $this->versionConflict($response, self::CODE_TYPE_INVALID, 'The provided entity type is invalid', "The type must be '" . self::INFO_TYPE_LOCALIZATION . "'.", '/data/type');
            return;
        }

        $locale = $attributes['locale'] ?? null;

        if (!\is_string($locale) || $locale === '') {
            $this->versionConflict($response, self::CODE_ATTRIBUTE_REQUIRED, 'The provided entity is missing a required attribute', "You must provide a value for the attribute 'locale' with this request", '/data/attributes/locale');
            return;
        }

        $appInfoId = $this->versionLinkage($relationships, 'appInfo', self::INFO_TYPE);
        $info = $appInfoId === null ? null : $this->info($cache, $appInfoId);

        if ($info === null || !$this->owns($cache, $key['issuerId'], $info[self::INTERNAL]['appId'])) {
            $this->versionConflict($response, self::CODE_RELATIONSHIP_INVALID, 'The provided entity includes a relationship with an invalid value', 'The app information does not exist.', '/data/relationships/appInfo');
            return;
        }

        $text = \array_intersect_key($attributes, \array_flip(self::INFO_LOCALIZATION_ATTRIBUTES));

        foreach ($text as $name => $value) {
            if ($value !== null && !\is_string($value)) {
                $this->versionConflict($response, self::CODE_ATTRIBUTE_INVALID, 'An attribute value is invalid.', "The attribute '{$name}' has an invalid value.", '/data/attributes/' . $name);
                return;
            }
        }

        if ($this->infoLocked($response, $info)) {
            return;
        }

        foreach ($info[self::INTERNAL]['localizations'] as $existing) {
            if ($existing['attributes']['locale'] === $locale) {
                $this->versionConflict($response, self::CODE_ATTRIBUTE_INVALID, 'An attribute value is invalid.', "A localization for the locale '{$locale}' already exists.", '/data/attributes/locale');
                return;
            }
        }

        $localization = [
            'id' => $this->uuid(),
            'attributes' => [
                'locale' => $locale,
                ...\array_fill_keys(self::INFO_LOCALIZATION_ATTRIBUTES, null),
                ...$text,
            ],
        ];

        $info[self::INTERNAL]['localizations'][] = $localization;

        $this->saveInfo($cache, $lock, $info);

        $this->respond($response, [
            'data' => $this->infoLocalizationResource($localization),
            'links' => ['self' => $this->link($request)],
        ], Response::STATUS_CODE_CREATED);
    }
}
