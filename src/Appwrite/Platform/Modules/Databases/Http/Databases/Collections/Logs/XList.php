<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Logs;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Action;
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
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Queries;
use Utopia\Database\Validator\Query\Limit;
use Utopia\Database\Validator\Query\Offset;
use Utopia\Database\Validator\UID;
use Utopia\Locale\Locale;
use Utopia\Swoole\Response as SwooleResponse;

class XList extends Action
{
    public static function getName(): string
    {
        return 'listCollectionLogs';
    }

    protected function getResponseModel(): string
    {
        return UtopiaResponse::MODEL_LOG_LIST;
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/databases/:databaseId/collections/:collectionId/logs')
            ->desc('List collection logs')
            ->groups(['api', 'database'])
            ->label('scope', 'collections.read')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('sdk', new Method(
                namespace: 'databases',
                group: $this->getSDKGroup(),
                name: self::getName(),
                description: '/docs/references/databases/get-collection-logs.md',
                auth: [AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_OK,
                        model: $this->getResponseModel(),
                    )
                ],
                contentType: ContentType::JSON,
                deprecated: new Deprecated(
                    since: '1.8.0',
                    replaceWith: 'tablesDB.listTableLogs',
                ),
            ))
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('collectionId', '', new UID(), 'Collection ID.')
            ->param('queries', [], new Queries([new Limit(), new Offset()]), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Only supported methods are limit and offset', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('locale')
            ->inject('geodb')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(string $databaseId, string $collectionId, array $queries, UtopiaResponse $response, Database $dbForProject, Locale $locale, Reader $geodb, Authorization $authorization): void
    {
        $database = $authorization->skip(fn () => $dbForProject->getDocument('databases', $databaseId));

        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND, params: [$databaseId]);
        }

        $collectionDocument = $dbForProject->getDocument('database_' . $database->getSequence(), $collectionId);
        $collection = $dbForProject->getCollection('database_' . $database->getSequence() . '_collection_' . $collectionDocument->getSequence());

        if ($collection->isEmpty()) {
            throw new Exception($this->getNotFoundException(), params: [$collectionId]);
        }

        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        $audit = new Audit($dbForProject);
        $context = $this->getContext();
        $resource = "database/$databaseId/$context/$collectionId";
        $logs = $audit->getLogsByResource($resource, $queries);

        $output = [];

        foreach ($logs as $i => &$log) {
            $log['userAgent'] = (!empty($log['userAgent'])) ? $log['userAgent'] : 'UNKNOWN';

            $detector = new Detector($log['userAgent']);
            $detector->skipBotDetection(); // OPTIONAL: If called, bot detection will completely be skipped (bots will be detected as regular devices then)

            $os = $detector->getOS() ?: [];
            $client = $detector->getClient() ?: [];
            $device = $detector->getDevice() ?: [];

            $output[$i] = new Document([
                'event' => $log['event'],
                'userId' => $log['data']['userId'],
                'userEmail' => $log['data']['userEmail'] ?? null,
                'userName' => $log['data']['userName'] ?? null,
                'mode' => $log['data']['mode'] ?? null,
                'ip' => $log['ip']  ?? null,
                'time' => $log['time'] ?? null,
                'osCode' => $os['osCode'] ?? null,
                'osName' => $os['osName'] ?? null,
                'osVersion' => $os['osVersion'] ?? null,
                'clientType' => $client['clientType'] ?? null,
                'clientCode' => $client['clientCode'] ?? null,
                'clientName' => $client['clientName'] ?? null,
                'clientVersion' => $client['clientVersion'] ?? null,
                'clientEngine' => $client['clientEngine'] ?? null,
                'clientEngineVersion' => $client['clientEngineVersion'] ?? null,
                'deviceName' => $device['deviceName'] ?? null,
                'deviceBrand' => $device['deviceBrand'] ?? null,
                'deviceModel' => $device['deviceModel'] ?? null
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
            'logs' => $output,
            'total' => $audit->countLogsByResource($resource, $queries),
        ]), $this->getResponseModel());
    }
}
