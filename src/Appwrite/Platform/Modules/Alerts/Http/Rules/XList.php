<?php

namespace Appwrite\Platform\Modules\Alerts\Http\Rules;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Alerts\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\Queries\AlertRules;
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
        return 'listRules';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/alerts/rules')
            ->desc('List alert rules')
            ->groups(['api', 'alerts'])
            ->label('scope', 'alertRules.read')
            ->label('sdk', new Method(
                namespace: 'alerts',
                group: 'rules',
                name: 'listRules',
                description: <<<EOT
                Get a list of the project's alert rules. Use queries to filter by metric, resource or status, and `Query.search('search', term)` to search rule IDs, names, metrics and resources.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_ALERT_RULE_LIST,
                    ),
                ]
            ))
            ->param('queries', [], new AlertRules(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . \implode(', ', AlertRules::ALLOWED_ATTRIBUTES), true)
            ->param('total', true, new Boolean(true), 'When set to false, the total count returned will be 0 and will not be calculated.', true)
            ->inject('response')
            ->inject('project')
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
        Document $project,
        Database $dbForPlatform,
        Authorization $authorization,
    ): void {
        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        $projectInternalId = (string) $project->getSequence();
        $queries[] = Query::equal('projectInternalId', [$projectInternalId]);

        $cursor = Query::getCursorQueries($queries, false);
        $cursor = \reset($cursor);

        if ($cursor !== false) {
            $validator = new Cursor();
            if (!$validator->isValid($cursor)) {
                throw new Exception(Exception::GENERAL_QUERY_INVALID, $validator->getDescription());
            }

            $ruleId = $cursor->getValue();
            $cursorDocument = $authorization->skip(fn () => $dbForPlatform->findOne('alertRules', [
                Query::equal('$id', [$ruleId]),
                Query::equal('projectInternalId', [$projectInternalId]),
            ]));

            if ($cursorDocument->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Rule '{$ruleId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }

        $filterQueries = Query::groupByType($queries)->filters;

        try {
            $rules = $authorization->skip(fn () => $dbForPlatform->find('alertRules', $queries));
            $total = $includeTotal ? $authorization->skip(fn () => $dbForPlatform->count('alertRules', $filterQueries, APP_LIMIT_COUNT)) : 0;
        } catch (OrderException $e) {
            throw new Exception(Exception::DATABASE_QUERY_ORDER_NULL, "The order attribute '{$e->getAttribute()}' had a null value. Cursor pagination requires all documents order attribute values are non-null.");
        }

        foreach ($rules as $rule) {
            $rule->removeAttribute('webhookSecret');
        }

        $response->dynamic(new Document([
            'rules' => $rules,
            'total' => $total,
        ]), Response::MODEL_ALERT_RULE_LIST);
    }
}
