<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\Apps;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\Base;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Cache\Cache;
use Utopia\System\System;
use Utopia\Validator\Assoc;
use Utopia\Validator\Range;

class XList extends Base
{
    private const array ALLOWED_QUERY = [
        'filter' => ['bundleId'],
        'fields' => ['apps'],
    ];

    public static function getName(): string
    {
        return 'listDistributionAppleApps';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath(self::PREFIX . '/v1/apps')
            ->desc('List App Store Connect apps')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('filter', [], new Assoc(), 'Filters, as `filter[bundleId]`. The bundle ID filter matches by prefix.', true)
            ->param('fields', [], new Assoc(), 'Sparse fieldsets, as `fields[apps]`.', true)
            ->param('limit', 50, new Range(1, 200), 'Maximum number of apps to return.', true)
            ->inject('request')
            ->inject('response')
            ->inject('cache')
            ->callback($this->action(...));
    }

    /**
     * @param array<string, mixed> $filter
     * @param array<string, mixed> $fields
     */
    public function action(array $filter, array $fields, int $limit, Request $request, Response $response, Cache $cache): void
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

        $bundleIds = $filter['bundleId'] ?? '';

        if (!\is_string($bundleIds)) {
            $this->error(
                $response,
                Response::STATUS_CODE_BAD_REQUEST,
                self::CODE_PARAMETER_INVALID,
                'A parameter has an invalid value',
                'The bundle ID filter must be a comma separated list.',
                ['parameter' => 'filter[bundleId]'],
            );

            return;
        }

        $prefixes = \array_values(\array_filter(\explode(',', $bundleIds), fn (string $prefix): bool => $prefix !== ''));
        $apps = $cache->load(self::CACHE_APPS . $key['issuerId'], self::TTL);
        $data = [];

        foreach (\is_array($apps) ? $apps : [] as $app) {
            if ($prefixes !== [] && !$this->matches($app['attributes']['bundleId'], $prefixes)) {
                continue;
            }

            $app['attributes'] = $this->select($app['attributes'], $fields, 'apps');
            $data[] = $app;

            if (\count($data) === $limit) {
                break;
            }
        }

        $this->respond($response, [
            'data' => $data,
            'links' => ['self' => $this->link($request)],
        ]);
    }

    /**
     * @param list<string> $prefixes
     */
    private function matches(string $bundleId, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (\str_starts_with($bundleId, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
