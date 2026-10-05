<?php

namespace Appwrite\Platform\Modules\Users\Http\Users;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\Queries\Users;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Order as OrderException;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Query;
use Utopia\Database\Validator\Query\Cursor;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Boolean;
use Utopia\Validator\Text;

class XList extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'listUsers';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/users')
            ->desc('List users')
            ->groups(['api', 'users'])
            ->label('scope', 'users.read')
            ->label('sdk', new Method(
                namespace: 'users',
                group: 'users',
                name: 'list',
                description: '/docs/references/users/list-users.md',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_USER_LIST,
                    )
                ]
            ))
            ->param('queries', [], new Users(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', Users::ALLOWED_ATTRIBUTES), true)
            ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
            ->param('total', true, new Boolean(true), 'When set to false, the total count returned will be 0 and will not be calculated.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->callback($this->action(...));
    }

    public function action(array $queries, string $search, bool $includeTotal, Response $response, Database $dbForProject): void
    {
        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        if (!empty($search)) {
            $queries[] = Query::search('search', $search);
        }

        $cursor = Query::getCursorQueries($queries, false);
        $cursor = \reset($cursor);

        if ($cursor !== false) {
            $validator = new Cursor();
            if (!$validator->isValid($cursor)) {
                throw new Exception(Exception::GENERAL_QUERY_INVALID, $validator->getDescription());
            }

            $userId = $cursor->getValue();
            $cursorDocument = $dbForProject->getDocument('users', $userId);

            if ($cursorDocument->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "User '{$userId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }

        $selects = Query::getByType($queries, [Query::TYPE_SELECT]);

        $skipFilters = APP_USERS_SUBQUERIES;
        if (!empty($selects)) {
            // Targets are batch-loaded below only when no selects are given; otherwise
            // the per-user subquery still has to run.
            $skipFilters = \array_diff($skipFilters, ['subQueryTargets']);
        }

        $users = [];
        $total = 0;

        $dbForProject->skipFilters(function () use ($dbForProject, $queries, $includeTotal, &$users, &$total) {
            try {
                $users = $dbForProject->find('users', $queries);
                $total = $includeTotal ? $dbForProject->count('users', $queries, APP_LIMIT_COUNT) : 0;
            } catch (OrderException $e) {
                throw new Exception(Exception::DATABASE_QUERY_ORDER_NULL, "The order attribute '{$e->getAttribute()}' had a null value. Cursor pagination requires all documents order attribute values are non-null.");
            } catch (QueryException $e) {
                throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
            }
        }, $skipFilters);

        if (empty($selects) && !empty($users)) {
            $sequences = [];
            foreach ($users as $user) {
                $sequences[] = $user->getSequence();
            }

            try {
                $targets = $dbForProject->getAuthorization()->skip(fn () => $dbForProject->find('targets', [
                    Query::equal('userInternalId', $sequences),
                    Query::limit(PHP_INT_MAX),
                ]));
            } catch (QueryException $e) {
                throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
            }

            $targetsByUser = [];
            foreach ($targets as $target) {
                $targetsByUser[$target->getAttribute('userInternalId')][] = $target;
            }

            foreach ($users as $user) {
                $user->setAttribute('targets', $targetsByUser[$user->getSequence()] ?? []);
            }
        }

        $response->dynamic(new Document([
            'users' => $users,
            'total' => $total,
        ]), Response::MODEL_USER_LIST);
    }
}
