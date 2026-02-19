<?php

namespace Appwrite\Platform\Modules\Teams\Http\Memberships;

use Appwrite\Auth\MFA\Type\TOTP;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Database\Validator\Queries\Memberships;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Order as OrderException;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Query\Cursor;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Boolean;
use Utopia\Validator\Text;

class XList extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'listTeamMemberships';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/teams/:teamId/memberships')
            ->desc('List team memberships')
            ->groups(['api', 'teams'])
            ->label('scope', 'teams.read')
            ->label('sdk', new Method(
                namespace: 'teams',
                group: 'memberships',
                name: 'listMemberships',
                description: '/docs/references/teams/list-team-members.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_MEMBERSHIP_LIST,
                    )
                ]
            ))
            ->param('teamId', '', new UID(), 'Team ID.')
            ->param('queries', [], new Memberships(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', Memberships::ALLOWED_ATTRIBUTES), true)
            ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
            ->param('total', true, new Boolean(true), 'When set to false, the total count returned will be 0 and will not be calculated.', true)
            ->inject('response')
            ->inject('project')
            ->inject('dbForProject')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(string $teamId, array $queries, string $search, bool $includeTotal, Response $response, Document $project, Database $dbForProject, Authorization $authorization)
    {
        $team = $dbForProject->getDocument('teams', $teamId);

        if ($team->isEmpty()) {
            throw new Exception(Exception::TEAM_NOT_FOUND);
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
        $queries[] = Query::equal('teamInternalId', [$team->getSequence()]);

        $cursor = Query::getCursorQueries($queries, false);
        $cursor = \reset($cursor);

        if ($cursor !== false) {
            $validator = new Cursor();
            if (!$validator->isValid($cursor)) {
                throw new Exception(Exception::GENERAL_QUERY_INVALID, $validator->getDescription());
            }

            $membershipId = $cursor->getValue();
            $cursorDocument = $dbForProject->getDocument('memberships', $membershipId);

            if ($cursorDocument->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Membership '{$membershipId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }

        $filterQueries = Query::groupByType($queries)['filters'];
        try {
            $memberships = $dbForProject->find(
                collection: 'memberships',
                queries: $queries,
            );
            $total = $includeTotal ? $dbForProject->count(
                collection: 'memberships',
                queries: $filterQueries,
                max: APP_LIMIT_COUNT
            ) : 0;
        } catch (OrderException $e) {
            throw new Exception(Exception::DATABASE_QUERY_ORDER_NULL, "The order attribute '{$e->getAttribute()}' had a null value. Cursor pagination requires all documents order attribute values are non-null.");
        }


        $memberships = array_filter($memberships, fn (Document $membership) => !empty($membership->getAttribute('userId')));

        $membershipsPrivacy =  [
            'userName' => $project->getAttribute('auths', [])['membershipsUserName'] ?? true,
            'userEmail' => $project->getAttribute('auths', [])['membershipsUserEmail'] ?? true,
            'mfa' => $project->getAttribute('auths', [])['membershipsMfa'] ?? true,
        ];

        $roles = $authorization->getRoles();
        $isPrivilegedUser = User::isPrivileged($roles);
        $isAppUser = User::isApp($roles);

        $membershipsPrivacy = array_map(function ($privacy) use ($isPrivilegedUser, $isAppUser) {
            return $privacy || $isPrivilegedUser || $isAppUser;
        }, $membershipsPrivacy);

        $memberships = array_map(function ($membership) use ($dbForProject, $team, $membershipsPrivacy) {
            $user = !empty(array_filter($membershipsPrivacy))
                ? $dbForProject->getDocument('users', $membership->getAttribute('userId'))
                : new Document();

            if ($membershipsPrivacy['mfa']) {
                $mfa = $user->getAttribute('mfa', false);

                if ($mfa) {
                    $totp = TOTP::getAuthenticatorFromUser($user);
                    $totpEnabled = $totp && $totp->getAttribute('verified', false);
                    $emailEnabled = $user->getAttribute('email', false) && $user->getAttribute('emailVerification', false);
                    $phoneEnabled = $user->getAttribute('phone', false) && $user->getAttribute('phoneVerification', false);

                    if (!$totpEnabled && !$emailEnabled && !$phoneEnabled) {
                        $mfa = false;
                    }
                }

                $membership->setAttribute('mfa', $mfa);
            }

            if ($membershipsPrivacy['userName']) {
                $membership->setAttribute('userName', $user->getAttribute('name'));
            }

            if ($membershipsPrivacy['userEmail']) {
                $membership->setAttribute('userEmail', $user->getAttribute('email'));
            }

            $membership->setAttribute('teamName', $team->getAttribute('name'));

            return $membership;
        }, $memberships);

        $response->dynamic(new Document([
            'memberships' => $memberships,
            'total' => $total,
        ]), Response::MODEL_MEMBERSHIP_LIST);
    }
}
