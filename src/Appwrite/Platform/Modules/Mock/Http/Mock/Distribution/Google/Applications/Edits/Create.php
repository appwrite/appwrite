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

class Create extends Base
{
    public static function getName(): string
    {
        return 'createDistributionGoogleEdit';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath(self::APPLICATIONS . '/:packageName/edits')
            ->desc('Create Google Play edit')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('packageName', '', new Text(0), 'Application package name.')
            ->inject('request')
            ->inject('response')
            ->inject('cache')
            ->callback($this->action(...));
    }

    public function action(string $packageName, Request $request, Response $response, Cache $cache): void
    {
        $isDevelopment = System::getEnv('_APP_ENV', 'production') === 'development';

        if (!$isDevelopment) {
            throw new Exception(Exception::GENERAL_NOT_IMPLEMENTED);
        }

        $email = $this->authorize($request, $response, $cache);

        if ($email === null) {
            return;
        }

        $application = $this->application($response, $cache, $packageName, $email);

        if ($application === null) {
            return;
        }

        $edit = [
            'id' => \bin2hex(\random_bytes(10)),
            'expiryTimeSeconds' => (string) (\time() + self::TTL),
            'packageName' => $packageName,
            'email' => $email,
        ];

        foreach (self::CONTENT as $key) {
            $edit[$key] = $application[$key] ?? [];
        }

        $this->saveEdit($cache, $edit);
        $cache->save($this->current($packageName, $email), $edit['id'], '', self::TTL);

        $response->json($this->appEdit($edit));
    }
}
