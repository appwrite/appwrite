<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\Seeds\Uploads;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\Base;
use Appwrite\Utopia\Response;
use Utopia\Cache\Cache;
use Utopia\System\System;
use Utopia\Validator\JSON;
use Utopia\Validator\Nullable;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

class Create extends Base
{
    private const array STATES = [self::UPLOAD_STATE_COMPLETE, self::UPLOAD_STATE_FAILED];
    private const array PROCESSING_STATES = ['PROCESSING', 'VALID', 'FAILED', 'INVALID'];

    public static function getName(): string
    {
        return 'createDistributionAppleSeedUpload';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath(self::SEEDS . '/uploads')
            ->desc('Seed App Store Connect build upload outcome')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('appId', '', new Text(64), 'ID of the app the upload belongs to.')
            ->param('build', '', new Text(64), 'Build number, as `CFBundleVersion`, the outcome applies to.')
            ->param('state', self::UPLOAD_STATE_COMPLETE, new WhiteList(self::STATES, true), 'Upload state once the file is committed, `COMPLETE` or `FAILED`.', true)
            ->param('errors', [], new JSON(), 'Upload state errors, as a list of `{code, description}` objects.', true)
            ->param('processingState', null, new Nullable(new WhiteList(self::PROCESSING_STATES, true)), 'Processing state of the build created on `COMPLETE`. Defaults to `VALID`.', true)
            ->inject('response')
            ->inject('cache')
            ->callback($this->action(...));
    }

    /**
     * @param array<mixed>|string $errors
     */
    public function action(string $appId, string $build, string $state, array|string $errors, ?string $processingState, Response $response, Cache $cache): void
    {
        $isDevelopment = System::getEnv('_APP_ENV', 'production') === 'development';

        if (!$isDevelopment) {
            throw new Exception(Exception::GENERAL_NOT_IMPLEMENTED);
        }

        if (!\is_array($cache->load(self::CACHE_APP . $appId, self::TTL))) {
            throw new Exception(Exception::GENERAL_MOCK, 'App not found: ' . $appId);
        }

        if (\is_string($errors)) {
            $errors = \json_decode($errors, true);
        }

        if (!\is_array($errors) || !\array_is_list($errors)) {
            throw new Exception(Exception::GENERAL_MOCK, 'Errors must be a list of {code, description} objects');
        }

        $details = [];

        foreach ($errors as $error) {
            if (!\is_array($error) || !\is_string($error['code'] ?? null) || !\is_string($error['description'] ?? null)) {
                throw new Exception(Exception::GENERAL_MOCK, 'Errors must be a list of {code, description} objects');
            }

            $details[] = ['code' => $error['code'], 'description' => $error['description']];
        }

        $seed = [
            'state' => $state,
            'errors' => $details,
            'processingState' => $processingState,
        ];

        $cache->save(self::CACHE_UPLOAD_SEEDS . $appId . ':' . $build, $seed, '', self::TTL);

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->json(['appId' => $appId, 'build' => $build, ...$seed]);
    }
}
