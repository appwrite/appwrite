<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\Apps\AppStoreVersions;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\Base;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Cache\Cache;
use Utopia\System\System;
use Utopia\Validator\Assoc;
use Utopia\Validator\Range;
use Utopia\Validator\Text;

class XList extends Base
{
    private const array ALLOWED_QUERY = [
        'filter' => ['platform', 'versionString', 'appVersionState'],
    ];

    public static function getName(): string
    {
        return 'listDistributionAppleAppStoreVersions';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath(self::PREFIX . '/v1/apps/:appId/appStoreVersions')
            ->desc('List App Store Connect versions of an app')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('appId', '', new Text(64), 'App ID.')
            ->param('filter', [], new Assoc(), 'Filters, as `filter[platform]`, `filter[versionString]` and `filter[appVersionState]`, each a comma-separated list.', true)
            ->param('include', '', new Text(256, 0), 'Related resources to include, `build` and `appStoreVersionPhasedRelease`.', true)
            ->param('limit', 50, new Range(1, 200), 'Maximum number of versions to return.', true)
            ->inject('request')
            ->inject('response')
            ->inject('cache')
            ->callback($this->action(...));
    }

    /**
     * @param array<string, mixed> $filter
     */
    public function action(string $appId, array $filter, string $include, int $limit, Request $request, Response $response, Cache $cache): void
    {
        $isDevelopment = System::getEnv('_APP_ENV', 'production') === 'development';

        if (!$isDevelopment) {
            throw new Exception(Exception::GENERAL_NOT_IMPLEMENTED);
        }

        $key = $this->authorize($request, $response, $cache);

        if ($key === null) {
            return;
        }

        $filter = \array_intersect_key($filter, \array_flip(self::ALLOWED_QUERY['filter']));

        if (!$this->owns($cache, $key['issuerId'], $appId)) {
            $this->notFound($response, 'apps', $appId);
            return;
        }

        $versions = \array_values(\array_filter(
            $this->versions($cache, $appId),
            fn (array $version): bool => $this->matches($version['attributes'], $filter),
        ));

        \usort($versions, fn (array $a, array $b): int => $b['attributes']['createdDate'] <=> $a['attributes']['createdDate']);

        $page = \array_slice($versions, 0, $limit);
        $included = $this->versionInclude($include);
        $body = [
            'data' => \array_map(fn (array $version): array => $this->versionResource($version, $included), $page),
            'links' => ['self' => $this->link($request)],
            'meta' => ['paging' => ['total' => \count($versions), 'limit' => $limit]],
        ];

        if ($included !== []) {
            $body['included'] = $this->versionIncluded($cache, $page, $included);
        }

        $this->respond($response, $body);
    }

    /**
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $filter
     */
    private function matches(array $attributes, array $filter): bool
    {
        foreach ($filter as $name => $values) {
            if (!\is_string($values) || !\in_array($attributes[$name] ?? null, \explode(',', $values), true)) {
                return false;
            }
        }

        return true;
    }
}
