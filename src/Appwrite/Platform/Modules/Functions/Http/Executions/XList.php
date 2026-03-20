<?php

namespace Appwrite\Platform\Modules\Functions\Http\Executions;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Compute\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Database\Validator\Queries\Executions;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Order as OrderException;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
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
        return 'listExecutions';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/functions/:functionId/executions')
            ->desc('List executions')
            ->groups(['api', 'functions'])
            ->label('scope', 'execution.read')
            ->label('resourceType', RESOURCE_TYPE_FUNCTIONS)
            ->label('sdk', new Method(
                namespace: 'functions',
                group: 'executions',
                name: 'listExecutions',
                description: <<<EOT
                Get a list of all the current user function execution logs. You can use the query params to filter your results.
                EOT,
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_EXECUTION_LIST,
                    )
                ]
            ))
            ->param('functionId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Function ID.', false, ['dbForProject'])
            ->param('queries', [], new Executions(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', Executions::ALLOWED_ATTRIBUTES), true)
            ->param('total', true, new Boolean(true), 'When set to false, the total count returned will be 0 and will not be calculated.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(
        string $functionId,
        array $queries,
        bool $includeTotal,
        Response $response,
        Database $dbForProject,
        Authorization $authorization
    ) {
        $function = $authorization->skip(fn () => $dbForProject->getDocument('functions', $functionId));

        $isAPIKey = User::isApp($authorization->getRoles());
        $isPrivilegedUser = User::isPrivileged($authorization->getRoles());

        if ($function->isEmpty() || (!$function->getAttribute('enabled') && !$isAPIKey && !$isPrivilegedUser)) {
            throw new Exception(Exception::FUNCTION_NOT_FOUND);
        }

        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        // Set internal queries
        $queries[] = Query::equal('resourceInternalId', [$function->getSequence()]);
        $queries[] = Query::equal('resourceType', ['functions']);

        $cursor = Query::getCursorQueries($queries, false);
        $cursor = \reset($cursor);

        if ($cursor !== false) {
            $validator = new Cursor();
            if (!$validator->isValid($cursor)) {
                throw new Exception(Exception::GENERAL_QUERY_INVALID, $validator->getDescription());
            }

            $executionId = $cursor->getValue();
            $cursorDocument = $dbForProject->getDocument('executions', $executionId);

            if ($cursorDocument->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Execution '{$executionId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }

        // Calculate the cutoff datetime before which a waiting/processing execution is considered timed out.
        $timeout = $function->getAttribute('timeout', 900);
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
        // waiting/processing executions created before the timeout threshold, so timed-out
        // executions that were never marked failed in the DB are included in the results.
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

        // Override status in response for timed-out executions, but only when the caller
        // did not explicitly request a non-failed status (e.g. waiting/processing).
        if (empty(\array_diff($requestedStatuses, ['failed']))) {
            foreach ($results as $execution) {
                $status = $execution->getAttribute('status', '');
                if ($status === 'waiting' || $status === 'processing') {
                    $elapsed = \time() - \strtotime($execution->getCreatedAt());
                    if ($elapsed >= $timeout) {
                        $execution->setAttribute('status', 'failed');
                    }
                }
            }
        }

        $response->dynamic(new Document([
            'executions' => $results,
            'total' => $total,
        ]), Response::MODEL_EXECUTION_LIST);
    }
}
