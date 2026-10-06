<?php

namespace Appwrite\Platform\Modules\Users\Http\Users\Memberships;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\Queries\Memberships;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Query;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Boolean;
use Utopia\Validator\Text;

class XList extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'listUserMemberships';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/users/:userId/memberships')
            ->desc('List user memberships')
            ->groups(['api', 'users'])
            ->label('scope', 'users.read')
            ->label('sdk', new Method(
                namespace: 'users',
                group: 'memberships',
                name: 'listMemberships',
                description: '/docs/references/users/list-user-memberships.md',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_MEMBERSHIP_LIST,
                    )
                ]
            ))
            ->param('userId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'User ID.', false, ['dbForProject'])
            ->param('queries', [], new Memberships(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', Memberships::ALLOWED_ATTRIBUTES), true)
            ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
            ->param('total', true, new Boolean(true), 'When set to false, the total count returned will be 0 and will not be calculated.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->callback($this->action(...));
    }

    public function action(string $userId, array $queries, string $search, bool $includeTotal, Response $response, Database $dbForProject): void
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
        if (!empty($search)) {
            $queries[] = Query::search('search', $search);
        }
        // Set internal queries
        $queries[] = Query::equal('userInternalId', [$user->getSequence()]);
        $memberships = array_map(function ($membership) use ($dbForProject, $user) {
            $team = $dbForProject->getDocument('teams', $membership->getAttribute('teamId'));
            $membership
                ->setAttribute('teamName', $team->getAttribute('name'))
                ->setAttribute('userName', $user->getAttribute('name'))
                ->setAttribute('userEmail', $user->getAttribute('email'));
            return $membership;
        }, $dbForProject->find('memberships', $queries));

        $response->dynamic(new Document([
            'memberships' => $memberships,
            'total' => $includeTotal ? count($memberships) : 0,
        ]), Response::MODEL_MEMBERSHIP_LIST);
    }
}
