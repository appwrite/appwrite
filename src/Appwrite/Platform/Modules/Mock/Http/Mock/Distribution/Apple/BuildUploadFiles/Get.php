<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\BuildUploadFiles;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\Base;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Cache\Cache;
use Utopia\System\System;
use Utopia\Validator\Text;

class Get extends Base
{
    public static function getName(): string
    {
        return 'getDistributionAppleBuildUploadFile';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath(self::PREFIX . '/v1/buildUploadFiles/:buildUploadFileId')
            ->desc('Get App Store Connect build upload file')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('buildUploadFileId', '', new Text(64), 'Build upload file ID.')
            ->inject('request')
            ->inject('response')
            ->inject('cache')
            ->callback($this->action(...));
    }

    public function action(string $buildUploadFileId, Request $request, Response $response, Cache $cache): void
    {
        $isDevelopment = System::getEnv('_APP_ENV', 'production') === 'development';

        if (!$isDevelopment) {
            throw new Exception(Exception::GENERAL_NOT_IMPLEMENTED);
        }

        $key = $this->authorize($request, $response, $cache);

        if ($key === null) {
            return;
        }

        $file = $cache->load(self::CACHE_UPLOAD_FILES . $buildUploadFileId, self::TTL);

        if (!\is_array($file) || !$this->owns($cache, $key['issuerId'], $file['appId'])) {
            $this->notFound($response, 'buildUploadFiles', $buildUploadFileId);
            return;
        }

        $this->respond($response, [
            'data' => $this->buildUploadFileResource($file),
            'links' => ['self' => $this->link($request)],
        ]);
    }
}
