<?php

namespace Appwrite\Platform\Modules\Users\Http\Users\Logs;

use Appwrite\Detector\Detector;
use Appwrite\Extend\Exception;
use Appwrite\Geo\Geo;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
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
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Boolean;

class XList extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'listUserLogs';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/users/:userId/logs')
            ->desc('List user logs')
            ->groups(['api', 'users'])
            ->label('scope', 'users.read')
            ->label('sdk', new Method(
                namespace: 'users',
                group: 'logs',
                name: 'listLogs',
                description: '/docs/references/users/list-user-logs.md',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_LOG_LIST,
                    )
                ]
            ))
            ->param('userId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'User ID.', false, ['dbForProject'])
            ->param('queries', [], new Queries([new Limit(), new Offset()]), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Only supported methods are limit and offset', true)
            ->param('total', true, new Boolean(true), 'When set to false, the total count returned will be 0 and will not be calculated.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('geo')
            ->inject('audit')
            ->callback($this->action(...));
    }

    public function action(string $userId, array $queries, bool $includeTotal, Response $response, Database $dbForProject, Geo $geo, Audit $audit): void
    {
        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        $grouped = Query::groupByType($queries);
        $limit = (int) ($grouped['limit'] ?? 25);
        $offset = (int) ($grouped['offset'] ?? 0);

        $logs = $audit->getLogsByUser((string) $user->getSequence(), limit: $limit, offset: $offset);

        $output = [];

        foreach ($logs as $log) {
            $userAgent = $log->getUserAgent() !== '' ? $log->getUserAgent() : 'UNKNOWN';
            $detector = new Detector($userAgent);
            $detector->skipBotDetection();

            $data = $log->getData();
            $actorId = $data['userId'] ?? '';

            $document = new Document(\array_merge(
                [
                    'event' => $log->getEvent(),
                    'userId' => \is_string($actorId) && $actorId !== '' ? ID::custom($actorId) : '',
                    'userEmail' => $data['userEmail'] ?? null,
                    'userName' => $data['userName'] ?? null,
                    'mode' => $data['mode'] ?? null,
                    'userType' => $data['userType'] ?? null,
                    'ip' => $log->getIp(),
                    'time' => $log->getTime(),
                ],
                $detector->getOS(),
                $detector->getClient(),
                $detector->getDevice(),
            ));

            $record = $geo->get($log->getIp());
            $document
                ->setAttribute('countryCode', $record->isEmpty() ? '--' : \strtolower($record->getCountryCode()))
                ->setAttribute('countryName', $record->getCountryName());

            $output[] = $document;
        }

        $response->dynamic(new Document([
            'total' => $includeTotal ? $audit->countLogsByUser((string) $user->getSequence()) : 0,
            'logs' => $output,
        ]), Response::MODEL_LOG_LIST);
    }
}
