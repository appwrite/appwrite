<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\BundleIds;

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
        'filter' => ['identifier'],
    ];

    public static function getName(): string
    {
        return 'listDistributionAppleBundleIds';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath(self::PREFIX . '/v1/bundleIds')
            ->desc('List App Store Connect bundle IDs')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('filter', [], new Assoc(), 'Filters, as `filter[identifier]`, a comma-separated list. The identifier filter matches by prefix.', true)
            ->param('include', '', new Text(128, 0), 'Related resources to include, `bundleIdCapabilities`.', true)
            ->param('limit', 20, new Range(1, 200), 'Maximum number of bundle IDs to return.', true)
            ->inject('request')
            ->inject('response')
            ->inject('cache')
            ->callback($this->action(...));
    }

    /**
     * @param array<string, mixed> $filter
     */
    public function action(array $filter, string $include, int $limit, Request $request, Response $response, Cache $cache): void
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

        $team = $this->provisioning($response, $cache, $key['issuerId']);

        if ($team === null) {
            return;
        }

        $identifiers = $filter['identifier'] ?? '';

        if (!\is_string($identifiers)) {
            $this->error(
                $response,
                Response::STATUS_CODE_BAD_REQUEST,
                self::CODE_PARAMETER_INVALID,
                'A parameter has an invalid value',
                'The identifier filter must be a comma separated list.',
                ['parameter' => 'filter[identifier]'],
            );

            return;
        }

        if (!\in_array($include, ['', self::BUNDLE_TYPE_CAPABILITY], true)) {
            $this->error(
                $response,
                Response::STATUS_CODE_BAD_REQUEST,
                self::CODE_PARAMETER_INVALID,
                'A parameter has an invalid value',
                "'{$include}' is not a valid relationship name",
                ['parameter' => 'include'],
            );

            return;
        }

        $prefixes = \array_values(\array_filter(\explode(',', $identifiers), fn (string $prefix): bool => $prefix !== ''));
        $data = [];
        $included = [];

        foreach ($this->bundles($cache, $team['teamId']) as $bundle) {
            if ($prefixes !== [] && !$this->matches($bundle['attributes']['identifier'], $prefixes)) {
                continue;
            }

            $data[] = $this->bundleResource($bundle);

            if ($include !== '') {
                \array_push($included, ...\array_map($this->capabilityResource(...), $bundle[self::INTERNAL]['capabilities']));
            }

            if (\count($data) === $limit) {
                break;
            }
        }

        $body = [
            'data' => $data,
            'links' => ['self' => $this->link($request)],
        ];

        if ($include !== '') {
            $body['included'] = $included;
        }

        $this->respond($response, $body);
    }

    /**
     * @param list<string> $prefixes
     */
    private function matches(string $identifier, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (\str_starts_with($identifier, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
