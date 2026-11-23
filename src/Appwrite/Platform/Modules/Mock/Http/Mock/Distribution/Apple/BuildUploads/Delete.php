<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\BuildUploads;

use Appwrite\Extend\Exception;
use Appwrite\Locking\Lock;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\Base;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Cache\Cache;
use Utopia\System\System;
use Utopia\Validator\Text;

class Delete extends Base
{
    public static function getName(): string
    {
        return 'deleteDistributionAppleBuildUpload';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath(self::PREFIX . '/v1/buildUploads/:buildUploadId')
            ->desc('Delete App Store Connect build upload')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('buildUploadId', '', new Text(64), 'Build upload ID.')
            ->inject('request')
            ->inject('response')
            ->inject('cache')
            ->inject('lock')
            ->callback($this->action(...));
    }

    public function action(string $buildUploadId, Request $request, Response $response, Cache $cache, Lock $lock): void
    {
        $isDevelopment = System::getEnv('_APP_ENV', 'production') === 'development';

        if (!$isDevelopment) {
            throw new Exception(Exception::GENERAL_NOT_IMPLEMENTED);
        }

        $key = $this->authorize($request, $response, $cache);

        if ($key === null) {
            return;
        }

        $upload = $cache->load(self::CACHE_UPLOAD_BUILDS . $buildUploadId, self::TTL);

        if (!\is_array($upload) || !$this->owns($cache, $key['issuerId'], $upload['appId'])) {
            $this->notFound($response, 'buildUploads', $buildUploadId);
            return;
        }

        $this->locked($lock, self::CACHE_UPLOAD_BUILDS . $buildUploadId, function () use ($cache, $buildUploadId): void {
            $upload = $cache->load(self::CACHE_UPLOAD_BUILDS . $buildUploadId, self::TTL);

            if (!\is_array($upload)) {
                return;
            }

            if ($upload['fileId'] !== null) {
                $this->seal($cache, $upload['fileId']);
                $cache->purge(self::CACHE_UPLOAD_FILES . $upload['fileId']);
            }

            $cache->purge(self::CACHE_UPLOAD_NUMBERS . $upload['number']);
            $cache->purge(self::CACHE_UPLOAD_BUILDS . $buildUploadId);
        });

        $response->noContent();
    }
}
