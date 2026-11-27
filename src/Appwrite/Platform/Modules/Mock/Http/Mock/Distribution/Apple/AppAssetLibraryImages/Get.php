<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\AppAssetLibraryImages;

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
        return 'getDistributionAppleAssetLibraryImage';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath(self::PREFIX . '/v1/appAssetLibraryImages/:imageId')
            ->desc('Get App Store Connect asset library image')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('imageId', '', new Text(64), 'Image ID.')
            ->inject('request')
            ->inject('response')
            ->inject('cache')
            ->callback($this->action(...));
    }

    public function action(string $imageId, Request $request, Response $response, Cache $cache): void
    {
        $isDevelopment = System::getEnv('_APP_ENV', 'production') === 'development';

        if (!$isDevelopment) {
            throw new Exception(Exception::GENERAL_NOT_IMPLEMENTED);
        }

        $key = $this->authorize($request, $response, $cache);

        if ($key === null) {
            return;
        }

        $image = $this->image($cache, $imageId);

        if (!$this->ownsImage($cache, $key['issuerId'], $image)) {
            $this->notFound($response, self::IMAGE_TYPE, $imageId);
            return;
        }

        if ($image['attributes']['state'] === self::IMAGE_UPLOAD_COMPLETE) {
            $image = $this->imageSettle($cache, $image);
        }

        $this->respond($response, [
            'data' => $this->imageResource($image),
            'links' => ['self' => $this->link($request)],
        ]);
    }
}
