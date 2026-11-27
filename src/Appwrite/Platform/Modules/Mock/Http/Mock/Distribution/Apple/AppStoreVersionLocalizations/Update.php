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
use Utopia\Validator\Text;

class Update extends Base
{
    private const array ALLOWED_KEYS = [
        'data' => ['type', 'id', 'attributes'],
        'attributes' => ['description', 'keywords', 'marketingUrl', 'promotionalText', 'supportUrl', 'whatsNew'],
    ];

    public static function getName(): string
    {
        return 'updateDistributionAppleAppStoreVersionLocalization';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath(self::PREFIX . '/v1/appStoreVersionLocalizations/:localizationId')
            ->desc('Update App Store Connect version localization')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('localizationId', '', new Text(64), 'Localization ID.')
            ->param('data', [], new Assoc(), 'Localization resource with the text attributes to change.')
            ->inject('request')
            ->inject('response')
            ->inject('cache')
            ->inject('lock')
            ->callback($this->action(...));
    }

    /**
     * @param array<string, mixed> $data
     */
    public function action(string $localizationId, array $data, Request $request, Response $response, Cache $cache, Lock $lock): void
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

        $version = $this->versionOfLocalization($cache, $localizationId);

        if ($version === null || !$this->ownsVersion($cache, $key['issuerId'], $version)) {
            $this->notFound($response, self::VERSION_TYPE_LOCALIZATION, $localizationId);
            return;
        }

        if (($data['type'] ?? null) !== self::VERSION_TYPE_LOCALIZATION || ($data['id'] ?? null) !== $localizationId) {
            $this->versionConflict($response, self::CODE_TYPE_INVALID, 'The provided entity type is invalid', 'The type and id must match the localization being updated.', '/data');
            return;
        }

        foreach ($attributes as $name => $value) {
            if ($value !== null && !\is_string($value)) {
                $this->versionConflict($response, self::CODE_ATTRIBUTE_INVALID, 'An attribute value is invalid.', "The attribute '{$name}' has an invalid value.", '/data/attributes/' . $name);
                return;
            }
        }

        if (\array_diff(\array_keys($attributes), ['promotionalText']) !== [] && !\in_array($version['attributes']['appVersionState'], self::VERSION_PREPARABLE, true)) {
            $this->versionConflict($response, self::VERSION_CODE_STATE, 'The request cannot be fulfilled because of the state of another resource.', "The version is {$version['attributes']['appVersionState']} and its localizations cannot be edited.");
            return;
        }

        if (($attributes['whatsNew'] ?? null) !== null && !$this->versionReleased($cache, $version[self::INTERNAL]['appId'])) {
            $this->versionConflict($response, self::CODE_ATTRIBUTE_INVALID, 'An attribute value is invalid.', "The attribute 'whatsNew' cannot be set on the first version of an app.", '/data/attributes/whatsNew');
            return;
        }

        $updated = null;

        foreach ($version[self::INTERNAL]['localizations'] as $index => $localization) {
            if ($localization['id'] === $localizationId) {
                $localization['attributes'] = \array_replace($localization['attributes'], $attributes);
                $version[self::INTERNAL]['localizations'][$index] = $localization;
                $updated = $localization;
            }
        }

        if ($updated === null) {
            $this->notFound($response, self::VERSION_TYPE_LOCALIZATION, $localizationId);
            return;
        }

        $this->saveVersion($cache, $lock, $version);

        $this->respond($response, [
            'data' => $this->versionLocalizationResource($updated),
            'links' => ['self' => $this->link($request)],
        ]);
    }
}
