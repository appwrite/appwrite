<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases\Logs;

use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Deprecated;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use DeviceDetector\DeviceDetector as Detector;
use MaxMind\Db\Reader;
use Utopia\Audit\Audit;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;
use Utopia\Database\Validator\Queries;
use Utopia\Database\Validator\Query\Limit;
use Utopia\Database\Validator\Query\Offset;
use Utopia\Database\Validator\UID;
use Utopia\Locale\Locale;
use Utopia\Platform\Action;
use Utopia\Swoole\Response as SwooleResponse;

class XList extends Action
{
    public static function getName(): string
    {
        return 'listDatabaseLogs';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/databases/:databaseId/logs')
            ->desc('List database logs')
            ->groups(['api', 'database'])
            ->label('scope', 'databases.read')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('sdk', [
                new Method(
                    namespace: 'databases',
                    group: 'logs',
                    name: 'listLogs',
                    description: '/docs/references/databases/get-logs.md',
                    auth: [AuthType::ADMIN],
                    responses: [
                        new SDKResponse(
                            code: SwooleResponse::STATUS_CODE_OK,
                            model: UtopiaResponse::MODEL_LOG_LIST,
                        )
                    ],
                    contentType: ContentType::JSON,
                    deprecated: new Deprecated(
                        since: '1.8.0',
                        replaceWith: 'tablesDB.listDatabaseLogs',
                    )
                ),
            ])
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('queries', [], new Queries([new Limit(), new Offset()]), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Only supported methods are limit and offset', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('locale')
            ->inject('geodb')
            ->callback($this->action(...));
    }

    public function action(string $databaseId, array $queries, UtopiaResponse $response, Database $dbForProject, Locale $locale, Reader $geodb): void
    {
        $database = $dbForProject->getDocument('databases', $databaseId);

        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND, params: [$databaseId]);
        }

        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        $audit = new Audit($dbForProject);
        $resource = 'database/' . $databaseId;
        $logs = $audit->getLogsByResource($resource, $queries);

        $output = [];

        foreach ($logs as $i => &$log) {
            $log['userAgent'] = $log['userAgent'] ?: 'UNKNOWN';
            $detector = new Detector($log['userAgent']);
            $detector->skipBotDetection();

            $os = $detector->getOS();
            $client = $detector->getClient();
            $device = $detector->getDevice();

            $output[$i] = new Document([
                'event' => $log['event'],
                'userId' => ID::custom($log['data']['userId']),
                'userEmail' => $log['data']['userEmail'] ?? null,
                'userName' => $log['data']['userName'] ?? null,
                'mode' => $log['data']['mode'] ?? null,
                'ip' => $log['ip'],
                'time' => $log['time'],
                'osCode' => $os['osCode'],
                'osName' => $os['osName'],
                'osVersion' => $os['osVersion'],
                'clientType' => $client['clientType'],
                'clientCode' => $client['clientCode'],
                'clientName' => $client['clientName'],
                'clientVersion' => $client['clientVersion'],
                'clientEngine' => $client['clientEngine'],
                'clientEngineVersion' => $client['clientEngineVersion'],
                'deviceName' => $device['deviceName'],
                'deviceBrand' => $device['deviceBrand'],
                'deviceModel' => $device['deviceModel'],
            ]);

            $record = $geodb->get($log['ip']);
            if ($record) {
                $countryCode = strtolower($record['country']['iso_code']);
                $output[$i]['countryCode'] = $locale->getText("countries.{$countryCode}", false) ? $countryCode : '--';
                $output[$i]['countryName'] = $locale->getText("countries.{$countryCode}", $locale->getText('locale.country.unknown'));
            } else {
                $output[$i]['countryCode'] = '--';
                $output[$i]['countryName'] = $locale->getText('locale.country.unknown');
            }
        }

        $response->dynamic(new Document([
            'total' => $audit->countLogsByResource($resource, $queries),
            'logs' => $output,
        ]), UtopiaResponse::MODEL_LOG_LIST);
    }
}
