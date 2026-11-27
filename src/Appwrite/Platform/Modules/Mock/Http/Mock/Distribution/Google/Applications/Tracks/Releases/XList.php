<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Google\Applications\Tracks\Releases;

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
        return 'listDistributionGoogleReleases';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath(self::APPLICATIONS . '/:packageName/tracks/:track/releases')
            ->desc('List Google Play track release summaries')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('packageName', '', new Text(0), 'Application package name.')
            ->param('track', '', new Text(0), 'Track name.')
            ->inject('request')
            ->inject('response')
            ->inject('cache')
            ->callback($this->action(...));
    }

    public function action(string $packageName, string $track, Request $request, Response $response, Cache $cache): void
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

        if ($this->locate($application['tracks'] ?? [], $track) === null) {
            $this->notFound($response, 'trackNotFound', 'Track not found: ' . $track . '.');
            return;
        }

        $response->json([
            'releases' => $this->review($cache, $packageName)['releases'][$track] ?? [],
        ]);
    }
}
