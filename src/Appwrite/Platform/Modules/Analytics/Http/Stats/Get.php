<?php

namespace Appwrite\Platform\Modules\Analytics\Http\Stats;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Analytics\Storage\ClickHouse as AnalyticsClickHouse;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Query\Query as ClickHouseQuery;
use Utopia\Validator\Text;

class Get extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'getAnalyticsStats';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/analytics/apps/:appId/stats')
            ->desc('Get analytics stats')
            ->groups(['api', 'analytics'])
            ->label('scope', 'analytics.read')
            ->label('sdk', new Method(
                namespace: 'analytics',
                group: 'stats',
                name: 'getStats',
                description: 'Get aggregate analytics stats (visitors, pageviews) for an app.',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_ANALYTICS_STATS,
                    ),
                ],
            ))
            ->param('appId', '', new UID(), 'Analytics app unique ID.')
            ->param('dateRange', '30d', new Text(16), 'Date range shorthand (e.g. 7d, 30d).', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('analyticsStorage')
            ->callback($this->action(...));
    }

    public function action(
        string $appId,
        string $dateRange,
        Response $response,
        Database $dbForProject,
        AnalyticsClickHouse $analyticsStorage,
    ): void {
        $app = $dbForProject->getDocument('analyticsApps', $appId);
        if ($app->isEmpty()) {
            throw new Exception(Exception::DOCUMENT_NOT_FOUND);
        }

        [$start, $end] = $this->resolveDateRange($dateRange);

        $queries = [
            ClickHouseQuery::between('timestamp', $start, $end),
        ];

        $stats = $analyticsStorage->aggregate($app->getId(), $queries);

        $response->dynamic(new Document([
            'visitors' => $stats['visitors'],
            'pageviews' => $stats['pageviews'],
        ]), Response::MODEL_ANALYTICS_STATS);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolveDateRange(string $dateRange): array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $end = $now->format('Y-m-d H:i:s');

        $matches = [];
        if (\preg_match('/^(\d+)d$/', $dateRange, $matches)) {
            $days = (int) $matches[1];
            $start = $now->modify('-' . $days . ' days')->format('Y-m-d H:i:s');
            return [$start, $end];
        }

        $start = $now->modify('-30 days')->format('Y-m-d H:i:s');
        return [$start, $end];
    }
}
