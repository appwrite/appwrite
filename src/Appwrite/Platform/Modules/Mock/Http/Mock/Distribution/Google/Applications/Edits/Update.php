<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Google\Applications\Edits;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Google\Base;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Cache\Cache;
use Utopia\System\System;
use Utopia\Validator\Text;

class Update extends Base
{
    private const string VALIDATE = ':validate';

    public static function getName(): string
    {
        return 'updateDistributionGoogleEdit';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath(self::APPLICATIONS . '/:packageName/edits/:editId')
            ->desc('Update Google Play edit')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('packageName', '', new Text(0), 'Application package name.')
            ->param('editId', '', new Text(0), 'Edit ID followed by the custom method, such as `:validate`.')
            ->inject('request')
            ->inject('response')
            ->inject('cache')
            ->callback($this->action(...));
    }

    public function action(string $packageName, string $editId, Request $request, Response $response, Cache $cache): void
    {
        $isDevelopment = System::getEnv('_APP_ENV', 'production') === 'development';

        if (!$isDevelopment) {
            throw new Exception(Exception::GENERAL_NOT_IMPLEMENTED);
        }

        if (!\str_ends_with($editId, self::VALIDATE)) {
            $this->notFound($response, 'notFound', 'Method not found.');
            return;
        }

        $edit = $this->edit($request, $response, $cache, $packageName, \substr($editId, 0, -\strlen(self::VALIDATE)));

        if ($edit === null) {
            return;
        }

        $response->json($this->appEdit($edit));
    }
}
