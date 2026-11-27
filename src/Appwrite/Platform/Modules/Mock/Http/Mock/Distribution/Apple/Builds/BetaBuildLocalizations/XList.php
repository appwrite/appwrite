<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\Builds\BetaBuildLocalizations;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\Base;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Cache\Cache;
use Utopia\System\System;
use Utopia\Validator\Range;
use Utopia\Validator\Text;

class XList extends Base
{
    public static function getName(): string
    {
        return 'listDistributionAppleBuildBetaBuildLocalizations';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath(self::PREFIX . '/v1/builds/:buildId/betaBuildLocalizations')
            ->desc('List App Store Connect beta build localizations of a build')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('buildId', '', new Text(64), 'Build ID.')
            ->param('limit', 50, new Range(1, 200), 'Maximum number of localizations to return.', true)
            ->inject('request')
            ->inject('response')
            ->inject('cache')
            ->callback($this->action(...));
    }

    public function action(string $buildId, int $limit, Request $request, Response $response, Cache $cache): void
    {
        $isDevelopment = System::getEnv('_APP_ENV', 'production') === 'development';

        if (!$isDevelopment) {
            throw new Exception(Exception::GENERAL_NOT_IMPLEMENTED);
        }

        $key = $this->authorize($request, $response, $cache);

        if ($key === null) {
            return;
        }

        if ($this->betaBuild($cache, $key['issuerId'], $buildId) === null) {
            $this->notFound($response, 'builds', $buildId);
            return;
        }

        $localizations = $this->betaLocalizations($cache, $buildId);

        $this->respond($response, [
            'data' => \array_slice($localizations, 0, $limit),
            'links' => ['self' => $this->link($request)],
            'meta' => ['paging' => ['total' => \count($localizations), 'limit' => $limit]],
        ]);
    }
}
