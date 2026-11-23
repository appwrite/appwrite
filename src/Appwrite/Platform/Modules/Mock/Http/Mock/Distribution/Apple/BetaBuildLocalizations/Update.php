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
use Utopia\Validator\Text;

class Update extends Base
{
    private const string TYPE = 'betaBuildLocalizations';
    private const array ALLOWED_KEYS = [
        'data' => ['type', 'id', 'attributes'],
        'attributes' => ['locale', 'whatsNew'],
    ];

    public static function getName(): string
    {
        return 'updateDistributionAppleBetaBuildLocalization';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath(self::PREFIX . '/v1/betaBuildLocalizations/:betaBuildLocalizationId')
            ->desc('Update App Store Connect beta build localization')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('betaBuildLocalizationId', '', new Text(64), 'Beta build localization ID.')
            ->param('data', [], new Assoc(), 'Beta build localization resource with a `whatsNew` attribute. The locale cannot be changed.')
            ->inject('request')
            ->inject('response')
            ->inject('cache')
            ->inject('lock')
            ->callback($this->action(...));
    }

    /**
     * @param array<string, mixed> $data
     */
    public function action(string $betaBuildLocalizationId, array $data, Request $request, Response $response, Cache $cache, Lock $lock): void
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

        $stored = $this->betaLocalization($cache, $betaBuildLocalizationId);

        if ($stored === null || $this->betaBuild($cache, $key['issuerId'], $stored['buildId']) === null) {
            $this->notFound($response, self::TYPE, $betaBuildLocalizationId);
            return;
        }

        if (($data['type'] ?? null) !== self::TYPE) {
            $this->invalid($response, self::CODE_TYPE_INVALID, 'The provided entity type is invalid', "The type must be '" . self::TYPE . "'.", '/data/type');
            return;
        }

        if (($data['id'] ?? null) !== $betaBuildLocalizationId) {
            $this->invalid($response, self::CODE_ATTRIBUTE_INVALID, 'The provided entity id is invalid', "The id must be '{$betaBuildLocalizationId}'.", '/data/id');
            return;
        }

        if (\array_key_exists('locale', $attributes)) {
            $this->invalid($response, self::CODE_ATTRIBUTE_INVALID, 'An attribute value is invalid.', "The attribute 'locale' cannot be changed.", '/data/attributes/locale');
            return;
        }

        $whatsNew = $attributes['whatsNew'] ?? null;

        if ($whatsNew !== null && !\is_string($whatsNew)) {
            $this->invalid($response, self::CODE_ATTRIBUTE_INVALID, 'An attribute value is invalid.', "The attribute 'whatsNew' must be a string.", '/data/attributes/whatsNew');
            return;
        }

        $localization = $stored['localization'];

        if (\array_key_exists('whatsNew', $attributes)) {
            $localization['attributes']['whatsNew'] = $whatsNew;
            $this->saveBetaLocalization($cache, $lock, $stored['buildId'], $localization);
        }

        $this->respond($response, [
            'data' => $localization,
            'links' => ['self' => $this->link($request)],
        ]);
    }

    private function invalid(Response $response, string $code, string $title, string $detail, string $pointer): void
    {
        $this->error($response, Response::STATUS_CODE_CONFLICT, $code, $title, $detail, ['pointer' => $pointer]);
    }
}
