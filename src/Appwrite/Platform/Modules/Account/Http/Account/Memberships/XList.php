<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Memberships;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\Queries\Memberships;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Order as OrderException;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Query;
use Utopia\Database\Validator\Query\Cursor;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Boolean;

class XList extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'listMemberships';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/account/memberships')
            ->desc('List account memberships')
            ->groups(['api', 'account'])
            ->label('scope', 'account')
            ->label('sdk', new Method(
                namespace: 'account',
                group: 'teams',
                name: 'listMemberships',
                description: <<<EOT
                Get the team memberships of the currently logged in user.
                EOT,
                auth: [AuthType::SESSION, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_MEMBERSHIP_LIST,
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->param('queries', [], new Memberships(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', Memberships::ALLOWED_ATTRIBUTES), true)
            ->param('total', true, new Boolean(true), 'When set to false, the total count returned will be 0 and will not be calculated.', true)
            ->inject('response')
            ->inject('user')
            ->inject('dbForProject')
            ->callback($this->action(...));
    }

    public function action(array $queries, bool $includeTotal, Response $response, Document $user, Database $dbForProject): void
    {
        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }
        $queries[] = Query::equal('userInternalId', [$user->getSequence()]);

        $cursor = Query::getCursorQueries($queries, false);
        $cursor = \reset($cursor);

        if ($cursor !== false) {
            $validator = new Cursor();
            if (!$validator->isValid($cursor)) {
                throw new Exception(Exception::GENERAL_QUERY_INVALID, $validator->getDescription());
            }

            $membershipId = $cursor->getValue();
            $cursorDocument = $dbForProject->getDocument('memberships', $membershipId);

            if ($cursorDocument->isEmpty() || $cursorDocument->getAttribute('userInternalId') !== $user->getSequence()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Membership '{$membershipId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }

        $filterQueries = Query::groupByType($queries)->filters;
        try {
            $memberships = array_map(function ($membership) use ($dbForProject, $user) {
                $team = $dbForProject->getDocument('teams', $membership->getAttribute('teamId'));
                $membership
                    ->setAttribute('teamName', $team->getAttribute('name'))
                    ->setAttribute('userName', $user->getAttribute('name'))
                    ->setAttribute('userEmail', $user->getAttribute('email'));
                return $membership;
            }, $dbForProject->find('memberships', $queries));
            $total = $includeTotal ? $dbForProject->count('memberships', $filterQueries, APP_LIMIT_COUNT) : 0;
        } catch (OrderException $e) {
            throw new Exception(Exception::DATABASE_QUERY_ORDER_NULL, "The order attribute '{$e->getAttribute()}' had a null value. Cursor pagination requires all documents order attribute values are non-null.");
        }

        $response->dynamic(new Document([
            'memberships' => $memberships,
            'total' => $total,
        ]), Response::MODEL_MEMBERSHIP_LIST);
    }
}
