<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\Seeds\Images;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\Base;
use Appwrite\Utopia\Response;
use Utopia\Cache\Cache;
use Utopia\System\System;
use Utopia\Validator\JSON;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

class Create extends Base
{
    public static function getName(): string
    {
        return 'createDistributionAppleSeedImage';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath(self::SEEDS . '/images')
            ->desc('Seed App Store Connect asset library image outcome')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('appId', '', new Text(64), 'ID of the app whose asset library receives the image.')
            ->param('referenceName', '', new Text(255), 'Reference name of the image the outcome applies to.')
            ->param('state', self::IMAGE_PREPARE_FOR_SUBMISSION, new WhiteList(self::IMAGE_OUTCOMES, true), 'Image state once the upload is committed: `PREPARE_FOR_SUBMISSION`, `UPLOAD_COMPLETE` (still processing) or `FAILED`.', true)
            ->param('stateDetails', [], new JSON(), 'State details for a failed image, as a list of `{code, description}` objects.', true)
            ->inject('response')
            ->inject('cache')
            ->callback($this->action(...));
    }

    /**
     * @param array<mixed>|string $stateDetails
     */
    public function action(string $appId, string $referenceName, string $state, array|string $stateDetails, Response $response, Cache $cache): void
    {
        $isDevelopment = System::getEnv('_APP_ENV', 'production') === 'development';

        if (!$isDevelopment) {
            throw new Exception(Exception::GENERAL_NOT_IMPLEMENTED);
        }

        if (!\is_array($cache->load(self::CACHE_APP . $appId, self::TTL))) {
            throw new Exception(Exception::GENERAL_MOCK, 'App not found: ' . $appId);
        }

        if (\is_string($stateDetails)) {
            $stateDetails = \json_decode($stateDetails, true);
        }

        if (!\is_array($stateDetails) || !\array_is_list($stateDetails)) {
            throw new Exception(Exception::GENERAL_MOCK, 'State details must be a list of {code, description} objects');
        }

        $details = [];

        foreach ($stateDetails as $detail) {
            if (!\is_array($detail) || !\is_string($detail['code'] ?? null) || !\is_string($detail['description'] ?? null)) {
                throw new Exception(Exception::GENERAL_MOCK, 'State details must be a list of {code, description} objects');
            }

            $details[] = ['code' => $detail['code'], 'description' => $detail['description']];
        }

        $seed = ['state' => $state, 'stateDetails' => $details];

        $cache->save(self::CACHE_IMAGE_SEEDS . $appId . ':' . $referenceName, $seed, '', self::TTL);

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->json(['appId' => $appId, 'referenceName' => $referenceName, ...$seed]);
    }
}
