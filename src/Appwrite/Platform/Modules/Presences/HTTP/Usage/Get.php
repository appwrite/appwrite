<?php

namespace Appwrite\Platform\Modules\Presences\HTTP\Usage;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Platform\Action as PlatformAction;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Platform\Enum;
use Utopia\Validator\WhiteList;

class Get extends PlatformAction
{
    public static function getName()
    {
        return 'getUsage';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/presences/usage')
            ->desc('Get usage')
            ->groups(['api', 'presences', 'usage'])
            ->label('scope', 'presences.read')
            ->label('sdk', new Method(
                namespace: 'presences',
                group: null,
                name: 'getUsage',
                desc: 'Get presence usage',
                description: '/docs/references/presences/get-usage.md',
                auth: [AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_USAGE_PRESENCE,
                    ),
                ],
            ))
            ->param('range', '30d', new WhiteList(['24h', '30d', '90d']), 'Date range.', true, enum: new Enum(
                name: 'UsageRange',
                map: [
                    '24h' => 'Twenty Four Hours',
                    '30d' => 'Thirty Days',
                    '90d' => 'Ninety Days',
                ]
            ))
            ->inject('response')
            ->inject('dbForProject')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(
        string $range,
        Response $response,
        Database $dbForProject,
        Authorization $authorization
    ): void {
        $periods = Config::getParam('usage', []);
        $days = $periods[$range];
        $metric = METRIC_USERS_PRESENCE;
        $stats = [
            'total' => 0,
            'data' => [],
        ];
        $hasTotal = false;

        $authorization->skip(function () use ($dbForProject, $days, $metric, &$stats, &$hasTotal): void {
            $result = $dbForProject->findOne('stats', [
                Query::equal('metric', [$metric]),
                Query::equal('period', ['inf']),
            ]);

            $hasTotal = !$result->isEmpty();
            $stats['total'] = $result['value'] ?? 0;

            $results = $dbForProject->find('stats', [
                Query::equal('metric', [$metric]),
                Query::equal('period', [$days['period']]),
                Query::limit($days['limit']),
                Query::orderDesc('time'),
            ]);

            foreach ($results as $result) {
                $stats['data'][$result->getAttribute('time')] = [
                    'value' => $result->getAttribute('value'),
                ];
            }
        });

        if (!$hasTotal && !empty($stats['data'])) {
            $stats['total'] = \end($stats['data'])['value'] ?? 0;
        }

        $format = match ($days['period']) {
            '1h' => 'Y-m-d\TH:00:00.000P',
            '1d' => 'Y-m-d\T00:00:00.000P',
            default => throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Unsupported period: ' . $days['period']),
        };

        $usage = [];
        $leap = time() - ($days['limit'] * $days['factor']);
        while ($leap < time()) {
            $leap += $days['factor'];
            $formatDate = date($format, $leap);
            $usage[] = [
                'value' => $stats['data'][$formatDate]['value'] ?? 0,
                'date' => $formatDate,
            ];
        }

        $response->dynamic(new Document([
            'range' => $range,
            'usersOnlineTotal' => $stats['total'],
            'presences' => $usage,
        ]), Response::MODEL_USAGE_PRESENCE);
    }
}
