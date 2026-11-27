<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Google\Applications\Edits\Listings\Images;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Google\Base;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Cache\Cache;
use Utopia\System\System;
use Utopia\Validator\Text;

class Create extends Base
{
    private const string URL = 'https://play-lh.googleusercontent.com/';

    public static function getName(): string
    {
        return 'createDistributionGoogleImage';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath(self::PREFIX . '/upload/androidpublisher/v3/applications/:packageName/edits/:editId/listings/:language/:imageType')
            ->desc('Upload Google Play edit listing image')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('packageName', '', new Text(0), 'Application package name.')
            ->param('editId', '', new Text(0), 'Edit ID.')
            ->param('language', '', new Text(0), 'BCP-47 language tag of the listing.')
            ->param('imageType', '', new Text(0), 'Image type.')
            ->inject('request')
            ->inject('response')
            ->inject('cache')
            ->callback($this->action(...));
    }

    public function action(string $packageName, string $editId, string $language, string $imageType, Request $request, Response $response, Cache $cache): void
    {
        $isDevelopment = System::getEnv('_APP_ENV', 'production') === 'development';

        if (!$isDevelopment) {
            throw new Exception(Exception::GENERAL_NOT_IMPLEMENTED);
        }

        $edit = $this->edit($request, $response, $cache, $packageName, $editId);

        if ($edit === null) {
            return;
        }

        if ($request->getQuery('uploadType', '') !== 'media') {
            $this->badRequest($response, 'Upload type must be media.');
            return;
        }

        if (!isset(self::IMAGE_LIMITS[$imageType])) {
            $this->badRequest($response, 'Invalid image type: ' . $imageType . '.');
            return;
        }

        if (!\str_starts_with($request->getHeaderLine('content-type'), 'image/')) {
            $this->badRequest($response, 'Images must be uploaded with an image content type.');
            return;
        }

        $body = $request->getRawPayload();

        if ($body === '' || \strlen($body) > self::IMAGE_SIZE) {
            $this->badRequest($response, 'Images must be between 1 byte and ' . self::IMAGE_SIZE . ' bytes.');
            return;
        }

        $images = $edit['listings'][$language]['images'][$imageType] ?? [];

        if (\count($images) >= self::IMAGE_LIMITS[$imageType]) {
            $this->badRequest($response, 'At most ' . self::IMAGE_LIMITS[$imageType] . ' images of type ' . $imageType . ' are allowed.');
            return;
        }

        $id = \bin2hex(\random_bytes(12));
        $image = [
            'id' => $id,
            'url' => self::URL . $id,
            'sha1' => \hash('sha1', $body),
            'sha256' => \hash('sha256', $body),
        ];

        $edit['listings'][$language] ??= [];
        $edit['listings'][$language]['images'][$imageType] = [...$images, $image];
        $this->saveEdit($cache, $edit);

        $response->json(['image' => $image]);
    }
}
