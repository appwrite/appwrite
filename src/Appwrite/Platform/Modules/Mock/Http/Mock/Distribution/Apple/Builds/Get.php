<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\Builds;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\Base;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Cache\Cache;
use Utopia\System\System;
use Utopia\Validator\Assoc;
use Utopia\Validator\Text;

class Get extends Base
{
    private const array ALLOWED_QUERY = [
        'fields' => ['builds'],
    ];

    public static function getName(): string
    {
        return 'getDistributionAppleBuild';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath(self::PREFIX . '/v1/builds/:buildId')
            ->desc('Get App Store Connect build')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('buildId', '', new Text(64), 'Build ID.')
            ->param('fields', [], new Assoc(), 'Sparse fieldsets, as `fields[builds]`.', true)
            ->inject('request')
            ->inject('response')
            ->inject('cache')
            ->callback($this->action(...));
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function action(string $buildId, array $fields, Request $request, Response $response, Cache $cache): void
    {
        $isDevelopment = System::getEnv('_APP_ENV', 'production') === 'development';

        if (!$isDevelopment) {
            throw new Exception(Exception::GENERAL_NOT_IMPLEMENTED);
        }

        $key = $this->authorize($request, $response, $cache);

        if ($key === null) {
            return;
        }

        $fields = \array_intersect_key($fields, \array_flip(self::ALLOWED_QUERY['fields']));

        $appId = $cache->load(self::CACHE_BUILD . $buildId, self::TTL);
        $build = \is_string($appId) && $this->owns($cache, $key['issuerId'], $appId) ? $this->build($cache, $buildId) : null;

        if ($build === null) {
            $this->notFound($response, 'builds', $buildId);
            return;
        }

        $this->respond($response, [
            'data' => $this->exposeBuild($build, $fields),
            'links' => ['self' => $this->link($request)],
        ]);
    }
}
