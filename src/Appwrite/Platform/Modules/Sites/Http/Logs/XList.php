<?php

namespace Appwrite\Platform\Modules\Sites\Http\Logs;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Compute\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\Queries\Executions;
use Appwrite\Utopia\Database\Validator\Queries\Logs;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Order as OrderException;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Query;
use Utopia\Database\Validator\Query\Cursor;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Boolean;

class XList extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'listLogs';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/sites/:siteId/logs')
            ->desc('List logs')
            ->groups(['api', 'sites'])
            ->label('scope', 'log.read')
            ->label('resourceType', RESOURCE_TYPE_SITES)
            ->label('sdk', new Method(
                namespace: 'sites',
                group: 'logs',
                name: 'listLogs',
                description: <<<EOT
                Get a list of all site logs. You can use the query params to filter your results.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_EXECUTION_LIST,
                    )
                ]
            ))
            ->param('siteId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Site ID.', false, ['dbForProject'])
            ->param('queries', [], new Logs(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', Executions::ALLOWED_ATTRIBUTES), true)
            ->param('total', true, new Boolean(true), 'When set to false, the total count returned will be 0 and will not be calculated.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->callback($this->action(...));
    }

    public function action(string $siteId, array $queries, bool $includeTotal, Response $response, Database $dbForProject)
    {
        $site = $dbForProject->getDocument('sites', $siteId);

        if ($site->isEmpty() || !$site->getAttribute('enabled')) {
            throw new Exception(Exception::SITE_NOT_FOUND);
        }

        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        // Set internal queries
        $queries[] = Query::equal('resourceInternalId', [$site->getSequence()]);
        $queries[] = Query::equal('resourceType', ['sites']);

        $cursor = Query::getCursorQueries($queries, false);
        $cursor = \reset($cursor);

        if ($cursor !== false) {
            $validator = new Cursor();
            if (!$validator->isValid($cursor)) {
                throw new Exception(Exception::GENERAL_QUERY_INVALID, $validator->getDescription());
            }

            $logId = $cursor->getValue();
            $cursorDocument = $dbForProject->getDocument('executions', $logId);

            if ($cursorDocument->isEmpty() || $cursorDocument->getAttribute('resourceType') !== 'sites') {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Log '{$logId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }

        // Calculate the cutoff datetime before which a waiting/processing log is considered timed out.
        $timeout = $site->getAttribute('timeout', 30);
        $thresholdDate = new \DateTime("-{$timeout} seconds");
        $threshold = DateTime::format($thresholdDate);

        // Capture what statuses the caller explicitly requested, before we mutate the query.
        $requestedStatuses = [];
        foreach ($queries as $query) {
            if ($query->getMethod() === Query::TYPE_EQUAL && $query->getAttribute() === 'status') {
                $requestedStatuses = [...$requestedStatuses, ...$query->getValues()];
            }
        }

        // If the caller is filtering by 'failed', expand the DB query to also return
        // waiting/processing logs created before the timeout threshold, so timed-out
        // logs that were never marked failed in the DB are included in the results.
        foreach ($queries as $index => $query) {
            if ($query->getMethod() === Query::TYPE_EQUAL && $query->getAttribute() === 'status' && \in_array('failed', $query->getValues())) {
                $queries[$index] = Query::or([
                    $query,
                    Query::and([
                        Query::equal('status', ['waiting', 'processing']),
                        Query::createdBefore($threshold),
                    ]),
                ]);
                break;
            }
        }

        $filterQueries = Query::groupByType($queries)['filters'];

        try {
            $results = $dbForProject->find('executions', $queries);
            $total = $includeTotal ? $dbForProject->count('executions', $filterQueries, APP_LIMIT_COUNT) : 0;
        } catch (OrderException $e) {
            throw new Exception(Exception::DATABASE_QUERY_ORDER_NULL, "The order attribute '{$e->getAttribute()}' had a null value. Cursor pagination requires all documents order attribute values are non-null.");
        }

        // Override status in response for timed-out logs, but only when the caller
        // did not explicitly request a non-failed status (e.g. waiting/processing).
        if (empty(\array_diff($requestedStatuses, ['failed']))) {
            foreach ($results as $log) {
                $status = $log->getAttribute('status', '');
                if ($status === 'waiting' || $status === 'processing') {
                    $elapsed = \time() - \strtotime($log->getCreatedAt());
                    if ($elapsed >= $timeout) {
                        $log->setAttribute('status', 'failed');
                    }
                }
            }
        }

        $response->dynamic(new Document([
            'executions' => $results,
            'total' => $total,
        ]), Response::MODEL_EXECUTION_LIST); // TODO: Update response model
    }
}
