<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\ReviewSubmissions;

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
        'filter' => ['app', 'platform', 'state'],
    ];

    public static function getName(): string
    {
        return 'listDistributionAppleReviewSubmissions';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath(self::PREFIX . '/v1/reviewSubmissions')
            ->desc('List App Store Connect review submissions')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('filter', [], new Assoc(), 'Filters, as `filter[app]`, `filter[platform]` and `filter[state]`. The app filter is required.', true)
            ->param('include', '', new Text(256, 0), 'Related resources to include, `items` and `appStoreVersionForReview`.', true)
            ->param('limit', 50, new Range(1, 200), 'Maximum number of review submissions to return.', true)
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

        $appId = $filter['app'] ?? '';

        if (!\is_string($appId) || $appId === '') {
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

        $submissions = $this->owns($cache, $key['issuerId'], $appId) ? $this->versionSubmissions($cache, $appId) : [];
        $platforms = \is_string($filter['platform'] ?? null) ? \explode(',', $filter['platform']) : null;
        $states = \is_string($filter['state'] ?? null) ? \explode(',', $filter['state']) : null;

        $submissions = \array_values(\array_filter(
            $submissions,
            fn (array $submission): bool => ($platforms === null || \in_array($submission['attributes']['platform'], $platforms, true))
                && ($states === null || \in_array($submission['attributes']['state'], $states, true)),
        ));

        $page = \array_slice($submissions, 0, $limit);
        $included = $this->versionInclude($include);
        $body = [
            'data' => \array_map(fn (array $submission): array => $this->versionSubmissionResource($submission, $included), $page),
            'links' => ['self' => $this->link($request)],
            'meta' => ['paging' => ['total' => \count($submissions), 'limit' => $limit]],
        ];

        if (\in_array('items', $included, true)) {
            $body['included'] = [];

            foreach ($page as $submission) {
                \array_push($body['included'], ...\array_map($this->versionItemResource(...), $submission[self::INTERNAL]['items']));
            }
        }

        $this->respond($response, $body);
    }
}
