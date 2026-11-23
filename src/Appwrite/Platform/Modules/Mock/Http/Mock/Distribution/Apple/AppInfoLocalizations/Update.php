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
use Utopia\Validator\Text;

class Update extends Base
{
    private const array ALLOWED_KEYS = [
        'data' => ['type', 'id', 'attributes'],
        'attributes' => ['name', 'subtitle', 'privacyPolicyUrl', 'privacyChoicesUrl', 'privacyPolicyText'],
    ];

    public static function getName(): string
    {
        return 'updateDistributionAppleAppInfoLocalization';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath(self::PREFIX . '/v1/appInfoLocalizations/:localizationId')
            ->desc('Update App Store Connect app information localization')
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

        $info = $this->infoOfLocalization($cache, $localizationId);

        if ($info === null || !$this->owns($cache, $key['issuerId'], $info[self::INTERNAL]['appId'])) {
            $this->notFound($response, self::INFO_TYPE_LOCALIZATION, $localizationId);
            return;
        }

        if (($data['type'] ?? null) !== self::INFO_TYPE_LOCALIZATION || ($data['id'] ?? null) !== $localizationId) {
            $this->versionConflict($response, self::CODE_TYPE_INVALID, 'The provided entity type is invalid', 'The type and id must match the localization being updated.', '/data');
            return;
        }

        foreach ($attributes as $name => $value) {
            if ($value !== null && !\is_string($value)) {
                $this->versionConflict($response, self::CODE_ATTRIBUTE_INVALID, 'An attribute value is invalid.', "The attribute '{$name}' has an invalid value.", '/data/attributes/' . $name);
                return;
            }
        }

        if ($this->infoLocked($response, $info)) {
            return;
        }

        $updated = null;

        foreach ($info[self::INTERNAL]['localizations'] as $index => $localization) {
            if ($localization['id'] === $localizationId) {
                $localization['attributes'] = \array_replace($localization['attributes'], $attributes);
                $info[self::INTERNAL]['localizations'][$index] = $localization;
                $updated = $localization;
            }
        }

        if ($updated === null) {
            $this->notFound($response, self::INFO_TYPE_LOCALIZATION, $localizationId);
            return;
        }

        $this->saveInfo($cache, $lock, $info);

        $this->respond($response, [
            'data' => $this->infoLocalizationResource($updated),
            'links' => ['self' => $this->link($request)],
        ]);
    }
}
