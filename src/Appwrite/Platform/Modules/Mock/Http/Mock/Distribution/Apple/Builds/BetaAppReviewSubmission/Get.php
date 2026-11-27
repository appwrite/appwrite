<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\Builds\BetaAppReviewSubmission;

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
        return 'getDistributionAppleBuildBetaAppReviewSubmission';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath(self::PREFIX . '/v1/builds/:buildId/betaAppReviewSubmission')
            ->desc('Get App Store Connect beta app review submission of a build')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('buildId', '', new Text(64), 'Build ID.')
            ->inject('request')
            ->inject('response')
            ->inject('cache')
            ->callback($this->action(...));
    }

    public function action(string $buildId, Request $request, Response $response, Cache $cache): void
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

        $submission = $this->betaSubmission($cache, $buildId);

        if ($submission === null) {
            $this->notFound($response, 'betaAppReviewSubmissions', $buildId);
            return;
        }

        $this->respond($response, [
            'data' => $submission,
            'links' => ['self' => $this->link($request)],
        ]);
    }
}
