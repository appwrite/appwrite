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

class XList extends Base
{
    public static function getName(): string
    {
        return 'listDistributionGoogleImages';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath(self::APPLICATIONS . '/:packageName/edits/:editId/listings/:language/:imageType')
            ->desc('List Google Play edit listing images')
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

        if (!isset(self::IMAGE_LIMITS[$imageType])) {
            $this->badRequest($response, 'Invalid image type: ' . $imageType . '.');
            return;
        }

        $response->json([
            'images' => $edit['listings'][$language]['images'][$imageType] ?? [],
        ]);
    }
}
