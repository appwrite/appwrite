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
use Utopia\Validator\Range;
use Utopia\Validator\Text;

class XList extends Base
{
    private const string SORT_ASCENDING = 'uploadedDate';
    private const string SORT_DESCENDING = '-uploadedDate';
    private const string PLATFORM = 'IOS';
    private const array ALLOWED_QUERY = [
        'filter' => ['app', 'version', 'preReleaseVersion.version', 'preReleaseVersion.platform'],
        'fields' => ['builds'],
    ];

    public static function getName(): string
    {
        return 'listDistributionAppleBuilds';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath(self::PREFIX . '/v1/builds')
            ->desc('List App Store Connect builds')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('filter', [], new Assoc(), 'Filters, as `filter[app]`, `filter[version]`, `filter[preReleaseVersion.version]` and `filter[preReleaseVersion.platform]`, each a comma-separated list. The app filter is required.', true)
            ->param('sort', self::SORT_DESCENDING, new Text(128, 0), 'Sort order, `uploadedDate` or `-uploadedDate`. Defaults to newest first.', true)
            ->param('fields', [], new Assoc(), 'Sparse fieldsets, as `fields[builds]`.', true)
            ->param('limit', 50, new Range(1, 200), 'Maximum number of builds to return.', true)
            ->inject('request')
            ->inject('response')
            ->inject('cache')
            ->callback($this->action(...));
    }

    /**
     * @param array<string, mixed> $filter
     * @param array<string, mixed> $fields
     */
    public function action(array $filter, string $sort, array $fields, int $limit, Request $request, Response $response, Cache $cache): void
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
        $fields = \array_intersect_key($fields, \array_flip(self::ALLOWED_QUERY['fields']));

        $appIds = $filter['app'] ?? '';

        if (!\is_string($appIds) || $appIds === '') {
            $this->error(
                $response,
                Response::STATUS_CODE_BAD_REQUEST,
                self::CODE_PARAMETER_REQUIRED,
                'A required parameter is missing.',
                'The app filter is required.',
                ['parameter' => 'filter[app]'],
            );

            return;
        }

        if (!\in_array($sort, ['', self::SORT_ASCENDING, self::SORT_DESCENDING], true)) {
            $this->error(
                $response,
                Response::STATUS_CODE_BAD_REQUEST,
                self::CODE_PARAMETER_INVALID,
                'A parameter has an invalid value',
                "'{$sort}' is not a valid field name",
                ['parameter' => 'sort'],
            );

            return;
        }

        $builds = [];

        foreach (\explode(',', $appIds) as $appId) {
            $owner = $cache->load(self::CACHE_APP . $appId, self::TTL);

            if (!\is_array($owner) || $owner['issuerId'] !== $key['issuerId']) {
                continue;
            }

            $seeded = $cache->load(self::CACHE_BUILDS . $appId, self::TTL);
            \array_push($builds, ...(\is_array($seeded) ? $seeded : []));
        }

        $builds = \array_values(\array_filter($builds, fn (array $build): bool => $this->matches($filter['version'] ?? null, $build['attributes']['version'])
            && $this->matches($filter['preReleaseVersion.version'] ?? null, $build[self::INTERNAL]['marketingVersion'] ?? null)
            && $this->matches($filter['preReleaseVersion.platform'] ?? null, $build[self::INTERNAL]['platform'] ?? self::PLATFORM)));

        $direction = $sort === self::SORT_ASCENDING ? 1 : -1;

        if ($direction === -1) {
            $builds = \array_reverse($builds);
        }

        \usort($builds, fn (array $a, array $b): int => $direction * (
            new \DateTimeImmutable($a['attributes']['uploadedDate']) <=> new \DateTimeImmutable($b['attributes']['uploadedDate'])
        ));

        $data = [];

        foreach (\array_slice($builds, 0, $limit) as $build) {
            $data[] = $this->exposeBuild($build, $fields);
        }

        $this->respond($response, [
            'data' => $data,
            'links' => ['self' => $this->link($request)],
        ]);
    }

    private function matches(mixed $filter, mixed $value): bool
    {
        if (!\is_string($filter) || $filter === '') {
            return true;
        }

        return \in_array($value, \explode(',', $filter), true);
    }
}
