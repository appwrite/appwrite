<?php

namespace Appwrite\Platform\Modules\Databases\Http\Rows\Logs;

use Appwrite\Detector\Detector;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use MaxMind\Db\Reader;
use Utopia\Audit\Audit;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Queries;
use Utopia\Database\Validator\Query\Limit;
use Utopia\Database\Validator\Query\Offset;
use Utopia\Database\Validator\UID;
use Utopia\Locale\Locale;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Swoole\Response as SwooleResponse;

class XList extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'listRowLogs';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/databases/:databaseId/tables/:tableId/rows/:rowId/logs')
            ->httpAlias('/v1/databases/:databaseId/collections/:tableId/documents/:rowId/logs')
            ->desc('List row logs')
            ->groups(['api', 'database'])
            ->label('scope', 'documents.read')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('sdk', new Method(
                namespace: 'databases',
                group: 'logs',
                name: 'listRowLogs',
                description: '/docs/references/databases/get-document-logs.md',
                auth: [AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_OK,
                        model: UtopiaResponse::MODEL_LOG_LIST,
                    )
                ],
                contentType: ContentType::JSON,
            ))
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('tableId', '', new UID(), 'Collection ID.')
            ->param('rowId', '', new UID(), 'Row ID.')
            ->param('queries', [], new Queries([new Limit(), new Offset()]), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Only supported methods are limit and offset', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('locale')
            ->inject('geodb')
            ->callback([$this, 'action']);
    }

    public function action(string $databaseId, string $tableId, string $rowId, array $queries, UtopiaResponse $response, Database $dbForProject, Locale $locale, Reader $geodb): void
    {
        $database = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));

        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $table = $dbForProject->getDocument('database_' . $database->getInternalId(), $tableId);

        if ($table->isEmpty()) {
            throw new Exception(Exception::COLLECTION_NOT_FOUND);
        }

        $row = $dbForProject->getDocument('database_' . $database->getInternalId() . '_collection_' . $table->getInternalId(), $rowId);

        if ($row->isEmpty()) {
            throw new Exception(Exception::DOCUMENT_NOT_FOUND);
        }

        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        // Temp fix for logs
        $queries[] = Query::or([
            Query::greaterThan('$createdAt', DateTime::format(new \DateTime('2025-02-26T01:30+00:00'))),
            Query::lessThan('$createdAt', DateTime::format(new \DateTime('2025-02-13T00:00+00:00'))),
        ]);

        $audit = new Audit($dbForProject);
        $resource = 'database/' . $databaseId . '/table/' . $tableId . '/row/' . $row->getId();
        $logs = $audit->getLogsByResource($resource, $queries);

        $output = [];

        foreach ($logs as $i => &$log) {
            $log['userAgent'] = (!empty($log['userAgent'])) ? $log['userAgent'] : 'UNKNOWN';

            $detector = new Detector($log['userAgent']);
            $detector->skipBotDetection(); // OPTIONAL: If called, bot detection will completely be skipped (bots will be detected as regular devices then)

            $os = $detector->getOS();
            $client = $detector->getClient();
            $device = $detector->getDevice();

            $output[$i] = new Document([
                'event' => $log['event'],
                'userId' => $log['data']['userId'],
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
                'deviceModel' => $device['deviceModel']
            ]);

            $record = $geodb->get($log['ip']);

            if ($record) {
                $output[$i]['countryCode'] = $locale->getText('countries.' . strtolower($record['country']['iso_code']), false) ? \strtolower($record['country']['iso_code']) : '--';
                $output[$i]['countryName'] = $locale->getText('countries.' . strtolower($record['country']['iso_code']), $locale->getText('locale.country.unknown'));
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
