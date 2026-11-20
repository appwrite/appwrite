<?php

namespace Appwrite\Platform\Modules\Alerts\Http\EscalationPolicies;

use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\Queries\EscalationPolicies;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Order as OrderException;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Query\Cursor;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Boolean;

class XList extends Base
{
    use HTTP;

    public static function getName(): string
    {
        return 'listEscalationPolicies';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/organization/escalation-policies')
            ->desc('List escalation policies')
            ->groups(['api', 'organization', 'escalationPolicies'])
            ->label('scope', 'monitors.read')
            ->label('sdk', new Method(
                namespace: 'organization',
                group: 'escalationPolicies',
                name: 'listEscalationPolicies',
                description: <<<EOT
                Get a list of your organization's escalation policies. Use `Query.search('search', term)` to search policy IDs and names.
                EOT,
                auth: [AuthType::ADMIN, AuthType::ORGANIZATION],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_ESCALATION_POLICY_LIST,
                    ),
                ]
            ))
            ->param('queries', [], new EscalationPolicies(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . \implode(', ', EscalationPolicies::ALLOWED_ATTRIBUTES), true)
            ->param('total', true, new Boolean(true), 'When set to false, the total count returned will be 0 and will not be calculated.', true)
            ->inject('response')
            ->inject('team')
            ->inject('dbForPlatform')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    /**
     * @param array<string> $queries
     */
    public function action(
        array $queries,
        bool $includeTotal,
        Response $response,
        Document $team,
        Database $dbForPlatform,
        Authorization $authorization,
    ): void {
        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        $teamInternalId = (string) $team->getSequence();
        $queries[] = Query::equal('teamInternalId', [$teamInternalId]);

        $cursor = Query::getCursorQueries($queries, false);
        $cursor = \reset($cursor);

        if ($cursor !== false) {
            $validator = new Cursor();
            if (!$validator->isValid($cursor)) {
                throw new Exception(Exception::GENERAL_QUERY_INVALID, $validator->getDescription());
            }

            $escalationPolicyId = $cursor->getValue();
            $cursorDocument = $authorization->skip(fn () => $dbForPlatform->findOne('escalationPolicies', [
                Query::equal('$id', [$escalationPolicyId]),
                Query::equal('teamInternalId', [$teamInternalId]),
            ]));

            if ($cursorDocument->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Escalation policy '{$escalationPolicyId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }

        $filterQueries = Query::groupByType($queries)->filters;

        try {
            $policies = $authorization->skip(fn () => $dbForPlatform->find('escalationPolicies', $queries));
            $total = $includeTotal ? $authorization->skip(fn () => $dbForPlatform->count('escalationPolicies', $filterQueries, APP_LIMIT_COUNT)) : 0;
        } catch (OrderException $e) {
            throw new Exception(Exception::DATABASE_QUERY_ORDER_NULL, "The order attribute '{$e->getAttribute()}' had a null value. Cursor pagination requires all documents order attribute values are non-null.");
        }

        $response->dynamic(new Document([
            'escalationPolicies' => $policies,
            'total' => $total,
        ]), Response::MODEL_ESCALATION_POLICY_LIST);
    }
}
