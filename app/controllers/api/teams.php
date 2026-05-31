<?php

use Appwrite\Auth\MFA\Type\TOTP;
use Appwrite\Auth\Validator\Phone;
use Appwrite\Detector\Detector;
use Appwrite\Event\Delete;
use Appwrite\Event\Event;
use Appwrite\Event\Mail;
use Appwrite\Event\Messaging;
use Appwrite\Event\StatsUsage;
use Appwrite\Extend\Exception;
use Appwrite\Network\Validator\Email as EmailValidator;
use Appwrite\Network\Validator\Redirect;
use Appwrite\Platform\Workers\Deletes;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Template\Template;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Database\Validator\Queries\Memberships;
use Appwrite\Utopia\Database\Validator\Queries\Teams;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use libphonenumber\PhoneNumberUtil;
use MaxMind\Db\Reader;
use Utopia\Abuse\Abuse;
use Utopia\App;
use Utopia\Audit\Audit;
use Utopia\Auth\Proofs\Password;
use Utopia\Auth\Proofs\Token;
use Utopia\Auth\Store;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Authorization as AuthorizationException;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Exception\Order as OrderException;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Exception\Structure as StructureException;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Key;
use Utopia\Database\Validator\Queries;
use Utopia\Database\Validator\Query\Cursor;
use Utopia\Database\Validator\Query\Limit;
use Utopia\Database\Validator\Query\Offset;
use Utopia\Database\Validator\UID;
use Utopia\Emails\Email;
use Utopia\Locale\Locale;
use Utopia\System\System;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Assoc;
use Utopia\Validator\Boolean;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

App::post('/v1/teams')
    ->desc('Create team')
    ->groups(['api', 'teams'])
    ->label('event', 'teams.[teamId].create')
    ->label('scope', 'teams.write')
    ->label('audits.event', 'team.create')
    ->label('audits.resource', 'team/{response.$id}')
    ->label('sdk', new Method(
        namespace: 'teams',
        group: 'teams',
        name: 'create',
        description: '/docs/references/teams/create-team.md',
        auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_CREATED,
                model: Response::MODEL_TEAM,
            )
        ]
    ))
    ->param('teamId', '', new CustomId(), 'Team ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('name', null, new Text(128), 'Team name. Max length: 128 chars.')
    ->param('roles', ['owner'], new ArrayList(new Key(), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Array of strings. Use this param to set the roles in the team for the user who created it. The default role is **owner**. A role can be any string. Learn more about [roles and permissions](https://appwrite.io/docs/permissions). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' roles are allowed, each 32 characters long.', true)
    ->inject('response')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('authorization')
    ->inject('queueForEvents')
    ->action(function (string $teamId, string $name, array $roles, Response $response, Document $user, Database $dbForProject, Authorization $authorization, Event $queueForEvents) {

        $isPrivilegedUser = User::isPrivileged($authorization->getRoles());
        $isAppUser = User::isApp($authorization->getRoles());

        $teamId = $teamId == 'unique()' ? ID::unique() : $teamId;

        try {
            $team = $authorization->skip(fn () => $dbForProject->createDocument('teams', new Document([
                '$id' => $teamId,
                '$permissions' => [
                    Permission::read(Role::team($teamId)),
                    Permission::update(Role::team($teamId, 'owner')),
                    Permission::delete(Role::team($teamId, 'owner')),
                ],
                'name' => $name,
                'total' => ($isPrivilegedUser || $isAppUser) ? 0 : 1,
                'prefs' => new \stdClass(),
                'search' => implode(' ', [$teamId, $name]),
            ])));
        } catch (Duplicate $th) {
            throw new Exception(Exception::TEAM_ALREADY_EXISTS);
        }

        if (!$isPrivilegedUser && !$isAppUser) { // Don't add user on server mode
            if (!\in_array('owner', $roles)) {
                $roles[] = 'owner';
            }

            $membershipId = ID::unique();
            $membership = new Document([
                '$id' => $membershipId,
                '$permissions' => [
                    Permission::read(Role::user($user->getId())),
                    Permission::read(Role::team($team->getId())),
                    Permission::update(Role::user($user->getId())),
                    Permission::update(Role::team($team->getId(), 'owner')),
                    Permission::delete(Role::user($user->getId())),
                    Permission::delete(Role::team($team->getId(), 'owner')),
                ],
                'userId' => $user->getId(),
                'userInternalId' => $user->getSequence(),
                'teamId' => $team->getId(),
                'teamInternalId' => $team->getSequence(),
                'roles' => $roles,
                'invited' => DateTime::now(),
                'joined' => DateTime::now(),
                'confirm' => true,
                'secret' => '',
                'search' => implode(' ', [$membershipId, $user->getId()])
            ]);

            $membership = $dbForProject->createDocument('memberships', $membership);
            $dbForProject->purgeCachedDocument('users', $user->getId());
        }

        $queueForEvents->setParam('teamId', $team->getId());

        if (!empty($user->getId())) {
            $queueForEvents->setParam('userId', $user->getId());
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($team, Response::MODEL_TEAM);
    });

App::get('/v1/teams')
    ->desc('List teams')
    ->groups(['api', 'teams'])
    ->label('scope', 'teams.read')
    ->label('sdk', new Method(
        namespace: 'teams',
        group: 'teams',
        name: 'list',
        description: '/docs/references/teams/list-teams.md',
        auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_TEAM_LIST,
            )
        ]
    ))
    ->param('queries', [], new Teams(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', Teams::ALLOWED_ATTRIBUTES), true)
    ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
    ->param('total', true, new Boolean(true), 'When set to false, the total count returned will be 0 and will not be calculated.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (array $queries, string $search, bool $includeTotal, Response $response, Database $dbForProject) {


        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        if (!empty($search)) {
            $queries[] = Query::search('search', $search);
        }

        /**
         * Get cursor document if there was a cursor query, we use array_filter and reset for reference $cursor to $queries
         */
        $cursor = \array_filter($queries, function ($query) {
            return \in_array($query->getMethod(), [Query::TYPE_CURSOR_AFTER, Query::TYPE_CURSOR_BEFORE]);
        });
        $cursor = reset($cursor);
        if ($cursor) {
            /** @var Query $cursor */

            $validator = new Cursor();
            if (!$validator->isValid($cursor)) {
                throw new Exception(Exception::GENERAL_QUERY_INVALID, $validator->getDescription());
            }

            $teamId = $cursor->getValue();
            $cursorDocument = $dbForProject->getDocument('teams', $teamId);

            if ($cursorDocument->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Team '{$teamId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }

        $filterQueries = Query::groupByType($queries)['filters'];
        try {
            $results = $dbForProject->find('teams', $queries);
            $total = $includeTotal ? $dbForProject->count('teams', $filterQueries, APP_LIMIT_COUNT) : 0;
        } catch (OrderException $e) {
            throw new Exception(Exception::DATABASE_QUERY_ORDER_NULL, "The order attribute '{$e->getAttribute()}' had a null value. Cursor pagination requires all documents order attribute values are non-null.");
        }

        $response->dynamic(new Document([
            'teams' => $results,
            'total' => $total,
        ]), Response::MODEL_TEAM_LIST);
    });

App::get('/v1/teams/:teamId')
    ->desc('Get team')
    ->groups(['api', 'teams'])
    ->label('scope', 'teams.read')
    ->label('sdk', new Method(
        namespace: 'teams',
        group: 'teams',
        name: 'get',
        description: '/docs/references/teams/get-team.md',
        auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_TEAM,
            )
        ]
    ))
    ->param('teamId', '', new UID(), 'Team ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $teamId, Response $response, Database $dbForProject) {

        $team = $dbForProject->getDocument('teams', $teamId);

        if ($team->isEmpty()) {
            throw new Exception(Exception::TEAM_NOT_FOUND);
        }

        $response->dynamic($team, Response::MODEL_TEAM);
    });

App::get('/v1/teams/:teamId/prefs')
    ->desc('Get team preferences')
    ->groups(['api', 'teams'])
    ->label('scope', 'teams.read')
    ->label('sdk', new Method(
        namespace: 'teams',
        group: 'teams',
        name: 'getPrefs',
        description: '/docs/references/teams/get-team-prefs.md',
        auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_PREFERENCES,
            )
        ]
    ))
    ->param('teamId', '', new UID(), 'Team ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $teamId, Response $response, Database $dbForProject) {

        $team = $dbForProject->getDocument('teams', $teamId);

        if ($team->isEmpty()) {
            throw new Exception(Exception::TEAM_NOT_FOUND);
        }

        $prefs = $team->getAttribute('prefs', []);

        try {
            $prefs = new Document($prefs);
        } catch (StructureException $e) {
            throw new Exception(Exception::DOCUMENT_INVALID_STRUCTURE, $e->getMessage());
        }

        $response->dynamic($prefs, Response::MODEL_PREFERENCES);
    });

App::put('/v1/teams/:teamId')
    ->desc('Update name')
    ->groups(['api', 'teams'])
    ->label('event', 'teams.[teamId].update')
    ->label('scope', 'teams.write')
    ->label('audits.event', 'team.update')
    ->label('audits.resource', 'team/{response.$id}')
    ->label('sdk', new Method(
        namespace: 'teams',
        group: 'teams',
        name: 'updateName',
        description: '/docs/references/teams/update-team-name.md',
        auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_TEAM,
            )
        ]
    ))
    ->param('teamId', '', new UID(), 'Team ID.')
    ->param('name', null, new Text(128), 'New team name. Max length: 128 chars.')
    ->inject('requestTimestamp')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->action(function (string $teamId, string $name, ?\DateTime $requestTimestamp, Response $response, Database $dbForProject, Event $queueForEvents) {

        $team = $dbForProject->getDocument('teams', $teamId);

        if ($team->isEmpty()) {
            throw new Exception(Exception::TEAM_NOT_FOUND);
        }

        $team
            ->setAttribute('name', $name)
            ->setAttribute('search', implode(' ', [$teamId, $name]));

        $team = $dbForProject->updateDocument('teams', $team->getId(), $team);

        $queueForEvents->setParam('teamId', $team->getId());

        $response->dynamic($team, Response::MODEL_TEAM);
    });

App::put('/v1/teams/:teamId/prefs')
    ->desc('Update preferences')
    ->groups(['api', 'teams'])
    ->label('event', 'teams.[teamId].update.prefs')
    ->label('scope', 'teams.write')
    ->label('audits.event', 'team.update')
    ->label('audits.resource', 'team/{response.$id}')
    ->label('audits.userId', '{response.$id}')
    ->label('sdk', new Method(
        namespace: 'teams',
        group: 'teams',
        name: 'updatePrefs',
        description: '/docs/references/teams/update-team-prefs.md',
        auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_PREFERENCES,
            )
        ]
    ))
    ->param('teamId', '', new UID(), 'Team ID.')
    ->param('prefs', '', new Assoc(), 'Prefs key-value JSON object.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->action(function (string $teamId, array $prefs, Response $response, Database $dbForProject, Event $queueForEvents) {
        try {
            $prefs = new Document($prefs);
        } catch (StructureException $e) {
            throw new Exception(Exception::DOCUMENT_INVALID_STRUCTURE, $e->getMessage());
        }

        $team = $dbForProject->getDocument('teams', $teamId);

        if ($team->isEmpty()) {
            throw new Exception(Exception::TEAM_NOT_FOUND);
        }

        $team = $dbForProject->updateDocument('teams', $team->getId(), new Document([
            'prefs' => $prefs->getArrayCopy()
        ]));

        $queueForEvents->setParam('teamId', $team->getId());

        $response->dynamic($prefs, Response::MODEL_PREFERENCES);
    });

App::delete('/v1/teams/:teamId')
    ->desc('Delete team')
    ->groups(['api', 'teams'])
    ->label('event', 'teams.[teamId].delete')
    ->label('scope', 'teams.write')
    ->label('audits.event', 'team.delete')
    ->label('audits.resource', 'team/{request.teamId}')
    ->label('sdk', new Method(
        namespace: 'teams',
        group: 'teams',
        name: 'delete',
        description: '/docs/references/teams/delete-team.md',
        auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_NOCONTENT,
                model: Response::MODEL_NONE,
            )
        ],
        contentType: ContentType::NONE
    ))
    ->param('teamId', '', new UID(), 'Team ID.')
    ->inject('response')
    ->inject('getProjectDB')
    ->inject('dbForProject')
    ->inject('queueForDeletes')
    ->inject('queueForEvents')
    ->inject('project')
    ->action(function (string $teamId, Response $response, callable $getProjectDB, Database $dbForProject, Delete $queueForDeletes, Event $queueForEvents, Document $project) {

        $team = $dbForProject->getDocument('teams', $teamId);

        if ($team->isEmpty()) {
            throw new Exception(Exception::TEAM_NOT_FOUND);
        }

        if (!$dbForProject->deleteDocument('teams', $teamId)) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove team from DB');
        }

        $deletes = new Deletes();
        $deletes->deleteMemberships($getProjectDB, $team, $project);

        if ($project->getId() === 'console') {
            $queueForDeletes
                ->setType(DELETE_TYPE_TEAM_PROJECTS)
                ->setDocument($team);
        }

        $queueForEvents
            ->setParam('teamId', $team->getId())
            ->setPayload($response->output($team, Response::MODEL_TEAM))
        ;

        $response->noContent();
    });

App::post('/v1/teams/:teamId/memberships')
    ->desc('Create team membership')
    ->groups(['api', 'teams', 'auth'])
    ->label('event', 'teams.[teamId].memberships.[membershipId].create')
    ->label('scope', 'teams.write')
    ->label('auth.type', 'invites')
    ->label('audits.event', 'membership.create')
    ->label('audits.resource', 'team/{request.teamId}')
    ->label('audits.userId', '{request.userId}')
    ->label('sdk', new Method(
        namespace: 'teams',
        group: 'memberships',
        name: 'createMembership',
        description: '/docs/references/teams/create-team-membership.md',
        auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_CREATED,
                model: Response::MODEL_MEMBERSHIP,
            )
        ]
    ))
    ->label('abuse-limit', 10)
    ->param('teamId', '', new UID(), 'Team ID.')
    ->param('email', '', new EmailValidator(), 'Email of the new team member.', true)
    ->param('userId', '', new UID(), 'ID of the user to be added to a team.', true)
    ->param('phone', '', new Phone(), 'Phone number. Format this number with a leading \'+\' and a country code, e.g., +16175551212.', true)
    ->param('roles', [], function (Document $project) {
        if ($project->getId() === 'console') {
            $roles = array_keys(Config::getParam('roles', []));
            $roles = array_filter($roles, function ($role) {
                return !in_array($role, [User::ROLE_APPS, User::ROLE_GUESTS, User::ROLE_USERS]);
            });
            return new ArrayList(new WhiteList($roles), APP_LIMIT_ARRAY_PARAMS_SIZE);
        }
        return new ArrayList(new Key(), APP_LIMIT_ARRAY_PARAMS_SIZE);
    }, 'Array of strings. Use this param to set the user roles in the team. A role can be any string. Learn more about [roles and permissions](https://appwrite.io/docs/permissions). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' roles are allowed, each 32 characters long.', false, ['project'])
    ->param('url', '', fn ($redirectValidator) => $redirectValidator, 'URL to redirect the user back to your app from the invitation email. This parameter is not required when an API key is supplied. Only URLs from hostnames in your project platform list are allowed. This requirement helps to prevent an [open redirect](https://cheatsheetseries.owasp.org/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.html) attack against your project API.', true, ['redirectValidator']) // TODO add our own built-in confirm page
    ->param('name', '', new Text(128), 'Name of the new team member. Max length: 128 chars.', true)
    ->inject('response')
    ->inject('project')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('authorization')
    ->inject('locale')
    ->inject('queueForMails')
    ->inject('queueForMessaging')
    ->inject('queueForEvents')
    ->inject('timelimit')
    ->inject('queueForStatsUsage')
    ->inject('plan')
    ->inject('proofForPassword')
    ->inject('proofForToken')
    ->action(function (string $teamId, string $email, string $userId, string $phone, array $roles, string $url, string $name, Response $response, Document $project, Document $user, Database $dbForProject, Authorization $authorization, Locale $locale, Mail $queueForMails, Messaging $queueForMessaging, Event $queueForEvents, callable $timelimit, StatsUsage $queueForStatsUsage, array $plan, Password $proofForPassword, Token $proofForToken) {
        $isAppUser = User::isApp($authorization->getRoles());
        $isPrivilegedUser = User::isPrivileged($authorization->getRoles());

        $url = htmlentities($url);
        if (empty($url)) {
            if (!$isAppUser && !$isPrivilegedUser) {
                throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'URL is required');
            }
        }

        if (empty($userId) && empty($email) && empty($phone)) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'At least one of userId, email, or phone is required');
        }

        if (!$isPrivilegedUser && !$isAppUser && empty(System::getEnv('_APP_SMTP_HOST'))) {
            throw new Exception(Exception::GENERAL_SMTP_DISABLED);
        }

        $email = \strtolower($email);
        $name = empty($name) ? $email : $name;
        $team = $dbForProject->getDocument('teams', $teamId);

        if ($team->isEmpty()) {
            throw new Exception(Exception::TEAM_NOT_FOUND);
        }
        if (!empty($userId)) {
            $invitee = $dbForProject->getDocument('users', $userId);
            if ($invitee->isEmpty()) {
                throw new Exception(Exception::USER_NOT_FOUND, 'User with given userId doesn\'t exist.', 404);
            }
            if (!empty($email) && $invitee->getAttribute('email', '') !== $email) {
                throw new Exception(Exception::USER_ALREADY_EXISTS, 'Given userId and email doesn\'t match', 409);
            }
            if (!empty($phone) && $invitee->getAttribute('phone', '') !== $phone) {
                throw new Exception(Exception::USER_ALREADY_EXISTS, 'Given userId and phone doesn\'t match', 409);
            }
            $email = $invitee->getAttribute('email', '');
            $phone = $invitee->getAttribute('phone', '');
            $name = $invitee->getAttribute('name', '') ?: $name;
        } elseif (!empty($email)) {
            $invitee = $dbForProject->findOne('users', [Query::equal('email', [$email])]); // Get user by email address
            if (!$invitee->isEmpty() && !empty($phone) && $invitee->getAttribute('phone', '') !== $phone) {
                throw new Exception(Exception::USER_ALREADY_EXISTS, 'Given email and phone doesn\'t match', 409);
            }
        } elseif (!empty($phone)) {
            $invitee = $dbForProject->findOne('users', [Query::equal('phone', [$phone])]);
            if (!$invitee->isEmpty() && !empty($email) && $invitee->getAttribute('email', '') !== $email) {
                throw new Exception(Exception::USER_ALREADY_EXISTS, 'Given phone and email doesn\'t match', 409);
            }
        }

        if ($invitee->isEmpty()) { // Create new user if no user with same email found
            $limit = $project->getAttribute('auths', [])['limit'] ?? 0;

            if (!$isPrivilegedUser && !$isAppUser && $limit !== 0 && $project->getId() !== 'console') { // check users limit, console invites are allways allowed.
                $total = $dbForProject->count('users', [], APP_LIMIT_USERS);

                if ($total >= $limit) {
                    throw new Exception(Exception::USER_COUNT_EXCEEDED, 'Project registration is restricted. Contact your administrator for more information.');
                }
            }

            // Makes sure this email is not already used in another identity
            $identityWithMatchingEmail = $dbForProject->findOne('identities', [
                Query::equal('providerEmail', [$email]),
            ]);
            if (!$identityWithMatchingEmail->isEmpty()) {
                throw new Exception(Exception::USER_EMAIL_ALREADY_EXISTS);
            }

            try {
                $userId = ID::unique();
                $hash = $proofForPassword->hash($proofForPassword->generate());
                $emailCanonical = new Email($email);
            } catch (Throwable) {
                $emailCanonical = null;
            }

            $userId = ID::unique();

            $userDocument = new Document([
                '$id' => $userId,
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::read(Role::user($userId)),
                    Permission::update(Role::user($userId)),
                    Permission::delete(Role::user($userId)),
                ],
                'email' => empty($email) ? null : $email,
                'phone' => empty($phone) ? null : $phone,
                'emailVerification' => false,
                'status' => true,
                // TODO: Set password empty?
                'password' => $hash,
                'hash' => $proofForPassword->getHash()->getName(),
                'hashOptions' => $proofForPassword->getHash()->getOptions(),
                /**
                 * Set the password update time to 0 for users created using
                 * team invite and OAuth to allow password updates without an
                 * old password
                 */
                'passwordUpdate' => null,
                'registration' => DateTime::now(),
                'reset' => false,
                'name' => $name,
                'prefs' => new \stdClass(),
                'sessions' => null,
                'tokens' => null,
                'memberships' => null,
                'search' => implode(' ', [$userId, $email, $name]),
                'emailCanonical' => $emailCanonical?->getCanonical(),
                'emailIsCanonical' => $emailCanonical?->isCanonicalSupported(),
                'emailIsCorporate' => $emailCanonical?->isCorporate(),
                'emailIsDisposable' => $emailCanonical?->isDisposable(),
                'emailIsFree' => $emailCanonical?->isFree(),
            ]);

            try {
                $invitee = $authorization->skip(fn () => $dbForProject->createDocument('users', $userDocument));
            } catch (Duplicate $th) {
                throw new Exception(Exception::USER_ALREADY_EXISTS);
            }
        }

        $isOwner = $authorization->hasRole('team:' . $team->getId() . '/owner');

        if (!$isOwner && !$isPrivilegedUser && !$isAppUser) { // Not owner, not admin, not app (server)
            throw new Exception(Exception::USER_UNAUTHORIZED, 'User is not allowed to send invitations for this team');
        }

        $membership = $dbForProject->findOne('memberships', [
            Query::equal('userInternalId', [$invitee->getSequence()]),
            Query::equal('teamInternalId', [$team->getSequence()]),
        ]);

        $secret = $proofForToken->generate();
        if ($membership->isEmpty()) {
            $membershipId = ID::unique();
            $membership = new Document([
                '$id' => $membershipId,
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::update(Role::user($invitee->getId())),
                    Permission::update(Role::team($team->getId(), 'owner')),
                    Permission::delete(Role::user($invitee->getId())),
                    Permission::delete(Role::team($team->getId(), 'owner')),
                ],
                'userId' => $invitee->getId(),
                'userInternalId' => $invitee->getSequence(),
                'teamId' => $team->getId(),
                'teamInternalId' => $team->getSequence(),
                'roles' => $roles,
                'invited' => DateTime::now(),
                'joined' => ($isPrivilegedUser || $isAppUser) ? DateTime::now() : null,
                'confirm' => ($isPrivilegedUser || $isAppUser),
                'secret' => $proofForToken->hash($secret),
                'search' => implode(' ', [$membershipId, $invitee->getId()])
            ]);

            $membership = ($isPrivilegedUser || $isAppUser) ?
                $authorization->skip(fn () => $dbForProject->createDocument('memberships', $membership)) :
                $dbForProject->createDocument('memberships', $membership);

            if ($isPrivilegedUser || $isAppUser) {
                $authorization->skip(fn () => $dbForProject->increaseDocumentAttribute('teams', $team->getId(), 'total', 1));
            }
        } elseif ($membership->getAttribute('confirm') === false) {
            $membership->setAttribute('secret', $proofForToken->hash($secret));
            $membership->setAttribute('invited', DateTime::now());

            if ($isPrivilegedUser || $isAppUser) {
                $membership->setAttribute('joined', DateTime::now());
                $membership->setAttribute('confirm', true);
            }

            $membership = ($isPrivilegedUser || $isAppUser) ?
                $authorization->skip(fn () => $dbForProject->updateDocument('memberships', $membership->getId(), $membership)) :
                $dbForProject->updateDocument('memberships', $membership->getId(), $membership);
        } else {
            throw new Exception(Exception::MEMBERSHIP_ALREADY_CONFIRMED);
        }

        if ($isPrivilegedUser || $isAppUser) {
            $dbForProject->purgeCachedDocument('users', $invitee->getId());
        } else {
            $url = Template::parseURL($url);
            $url['query'] = Template::mergeQuery(((isset($url['query'])) ? $url['query'] : ''), ['membershipId' => $membership->getId(), 'userId' => $invitee->getId(), 'secret' => $secret, 'teamId' => $teamId, 'teamName' => $team->getAttribute('name')]);
            $url = Template::unParseURL($url);
            if (!empty($email)) {
                $projectName = $project->isEmpty() ? 'Console' : $project->getAttribute('name', '[APP-NAME]');

                $body = $locale->getText("emails.invitation.body");
                $preview = $locale->getText("emails.invitation.preview");
                $subject = $locale->getText("emails.invitation.subject");
                $customTemplate = $project->getAttribute('templates', [])['email.invitation-' . $locale->default] ?? [];

                $message = Template::fromFile(__DIR__ . '/../../config/locale/templates/email-inner-base.tpl');
                $message
                    ->setParam('{{body}}', $body, escapeHtml: false)
                    ->setParam('{{hello}}', $locale->getText("emails.invitation.hello"))
                    ->setParam('{{footer}}', $locale->getText("emails.invitation.footer"))
                    ->setParam('{{thanks}}', $locale->getText("emails.invitation.thanks"))
                    ->setParam('{{buttonText}}', $locale->getText("emails.invitation.buttonText"))
                    ->setParam('{{signature}}', $locale->getText("emails.invitation.signature"));
                $body = $message->render();

                $smtp = $project->getAttribute('smtp', []);
                $smtpEnabled = $smtp['enabled'] ?? false;

                $senderEmail = System::getEnv('_APP_SYSTEM_EMAIL_ADDRESS', APP_EMAIL_TEAM);
                $senderName = System::getEnv('_APP_SYSTEM_EMAIL_NAME', APP_NAME . ' Server');
                $replyTo = "";

                if ($smtpEnabled) {
                    if (!empty($smtp['senderEmail'])) {
                        $senderEmail = $smtp['senderEmail'];
                    }
                    if (!empty($smtp['senderName'])) {
                        $senderName = $smtp['senderName'];
                    }
                    if (!empty($smtp['replyTo'])) {
                        $replyTo = $smtp['replyTo'];
                    }

                    $queueForMails
                        ->setSmtpHost($smtp['host'] ?? '')
                        ->setSmtpPort($smtp['port'] ?? '')
                        ->setSmtpUsername($smtp['username'] ?? '')
                        ->setSmtpPassword($smtp['password'] ?? '')
                        ->setSmtpSecure($smtp['secure'] ?? '');

                    if (!empty($customTemplate)) {
                        if (!empty($customTemplate['senderEmail'])) {
                            $senderEmail = $customTemplate['senderEmail'];
                        }
                        if (!empty($customTemplate['senderName'])) {
                            $senderName = $customTemplate['senderName'];
                        }
                        if (!empty($customTemplate['replyTo'])) {
                            $replyTo = $customTemplate['replyTo'];
                        }

                        $body = $customTemplate['message'] ?? '';
                        $subject = $customTemplate['subject'] ?? $subject;
                    }

                    $queueForMails
                        ->setSmtpReplyTo($replyTo)
                        ->setSmtpSenderEmail($senderEmail)
                        ->setSmtpSenderName($senderName);
                }

                $emailVariables = [
                    'owner' => $user->getAttribute('name'),
                    'direction' => $locale->getText('settings.direction'),
                    /* {{user}}, {{team}}, {{redirect}} and {{project}} are required in default and custom templates */
                    'user' => $name,
                    'team' => $team->getAttribute('name'),
                    'redirect' => $url,
                    'project' => $projectName
                ];

                $queueForMails
                    ->setSubject($subject)
                    ->setBody($body)
                    ->setPreview($preview)
                    ->setRecipient($invitee->getAttribute('email'))
                    ->setName($invitee->getAttribute('name', ''))
                    ->setVariables($emailVariables)
                    ->trigger();
            } elseif (!empty($phone)) {
                if (empty(System::getEnv('_APP_SMS_PROVIDER'))) {
                    throw new Exception(Exception::GENERAL_PHONE_DISABLED, 'Phone provider not configured');
                }

                $message = Template::fromFile(__DIR__ . '/../../config/locale/templates/sms-base.tpl');

                $customTemplate = $project->getAttribute('templates', [])['sms.invitation-' . $locale->default] ?? [];
                if (!empty($customTemplate)) {
                    $message = $customTemplate['message'];
                }

                $message = $message->setParam('{{token}}', $url);
                $message = $message->render();

                $messageDoc = new Document([
                    '$id' => ID::unique(),
                    'data' => [
                        'content' => $message,
                    ],
                ]);

                $queueForMessaging
                    ->setType(MESSAGE_SEND_TYPE_INTERNAL)
                    ->setMessage($messageDoc)
                    ->setRecipients([$phone])
                    ->setProviderType('SMS');

                if (isset($plan['authPhone'])) {
                    $timelimit = $timelimit('organization:{organizationId}', $plan['authPhone'], 30 * 24 * 60 * 60); // 30 days
                    $timelimit
                        ->setParam('{organizationId}', $project->getAttribute('teamId'));

                    $abuse = new Abuse($timelimit);
                    if ($abuse->check() && System::getEnv('_APP_OPTIONS_ABUSE', 'enabled') === 'enabled') {
                        $helper = PhoneNumberUtil::getInstance();
                        $countryCode = $helper->parse($phone)->getCountryCode();

                        if (!empty($countryCode)) {
                            $queueForStatsUsage
                                ->addMetric(str_replace('{countryCode}', $countryCode, METRIC_AUTH_METHOD_PHONE_COUNTRY_CODE), 1);
                        }
                    }
                    $queueForStatsUsage
                        ->addMetric(METRIC_AUTH_METHOD_PHONE, 1)
                        ->setProject($project)
                        ->trigger();
                }
            }
        }

        $queueForEvents
            ->setParam('userId', $invitee->getId())
            ->setParam('teamId', $team->getId())
            ->setParam('membershipId', $membership->getId())
        ;

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic(
                $membership
                    ->setAttribute('teamName', $team->getAttribute('name'))
                    ->setAttribute('userName', $invitee->getAttribute('name'))
                    ->setAttribute('userEmail', $invitee->getAttribute('email')),
                Response::MODEL_MEMBERSHIP
            );
    });

App::get('/v1/teams/:teamId/memberships')
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
    ->action(function (string $teamId, array $queries, string $search, bool $includeTotal, Response $response, Document $project, Database $dbForProject, Authorization $authorization) {
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

        /**
         * Get cursor document if there was a cursor query, we use array_filter and reset for reference $cursor to $queries
         */
        $cursor = \array_filter($queries, function ($query) {
            return \in_array($query->getMethod(), [Query::TYPE_CURSOR_AFTER, Query::TYPE_CURSOR_BEFORE]);
        });
        $cursor = reset($cursor);
        if ($cursor) {
            /** @var Query $cursor */

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
    });

App::get('/v1/teams/:teamId/memberships/:membershipId')
    ->desc('Get team membership')
    ->groups(['api', 'teams'])
    ->label('scope', 'teams.read')
    ->label('sdk', new Method(
        namespace: 'teams',
        group: 'memberships',
        name: 'getMembership',
        description: '/docs/references/teams/get-team-member.md',
        auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_MEMBERSHIP,
            )
        ]
    ))
    ->param('teamId', '', new UID(), 'Team ID.')
    ->param('membershipId', '', new UID(), 'Membership ID.')
    ->inject('response')
    ->inject('project')
    ->inject('dbForProject')
    ->inject('authorization')
    ->action(function (string $teamId, string $membershipId, Response $response, Document $project, Database $dbForProject, Authorization $authorization) {

        $team = $dbForProject->getDocument('teams', $teamId);

        if ($team->isEmpty()) {
            throw new Exception(Exception::TEAM_NOT_FOUND);
        }

        $membership = $dbForProject->getDocument('memberships', $membershipId);

        if ($membership->isEmpty() || empty($membership->getAttribute('userId'))) {
            throw new Exception(Exception::MEMBERSHIP_NOT_FOUND);
        }

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

        $response->dynamic($membership, Response::MODEL_MEMBERSHIP);
    });

App::patch('/v1/teams/:teamId/memberships/:membershipId')
    ->desc('Update membership')
    ->groups(['api', 'teams'])
    ->label('event', 'teams.[teamId].memberships.[membershipId].update')
    ->label('scope', 'teams.write')
    ->label('audits.event', 'membership.update')
    ->label('audits.resource', 'team/{request.teamId}')
    ->label('sdk', new Method(
        namespace: 'teams',
        group: 'memberships',
        name: 'updateMembership',
        description: '/docs/references/teams/update-team-membership.md',
        auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_MEMBERSHIP,
            )
        ]
    ))
    ->param('teamId', '', new UID(), 'Team ID.')
    ->param('membershipId', '', new UID(), 'Membership ID.')
    ->param('roles', [], function (Document $project) {
        if ($project->getId() === 'console') {
            $roles = array_keys(Config::getParam('roles', []));
            $roles = array_filter($roles, function ($role) {
                return !in_array($role, [User::ROLE_APPS, User::ROLE_GUESTS, User::ROLE_USERS]);
            });
            return new ArrayList(new WhiteList($roles), APP_LIMIT_ARRAY_PARAMS_SIZE);
        }
        return new ArrayList(new Key(), APP_LIMIT_ARRAY_PARAMS_SIZE);
    }, 'An array of strings. Use this param to set the user\'s roles in the team. A role can be any string. Learn more about [roles and permissions](https://appwrite.io/docs/permissions). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' roles are allowed, each 32 characters long.', false, ['project'])
    ->inject('request')
    ->inject('response')
    ->inject('user')
    ->inject('project')
    ->inject('dbForProject')
    ->inject('authorization')
    ->inject('queueForEvents')
    ->action(function (string $teamId, string $membershipId, array $roles, Request $request, Response $response, Document $user, Document $project, Database $dbForProject, Authorization $authorization, Event $queueForEvents) {

        $team = $dbForProject->getDocument('teams', $teamId);
        if ($team->isEmpty()) {
            throw new Exception(Exception::TEAM_NOT_FOUND);
        }

        $membership = $dbForProject->getDocument('memberships', $membershipId);
        if ($membership->isEmpty()) {
            throw new Exception(Exception::MEMBERSHIP_NOT_FOUND);
        }

        $profile = $dbForProject->getDocument('users', $membership->getAttribute('userId'));
        if ($profile->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $isPrivilegedUser = User::isPrivileged($authorization->getRoles());
        $isAppUser = User::isApp($authorization->getRoles());
        $isOwner = $authorization->hasRole('team:' . $team->getId() . '/owner');

        if ($project->getId() === 'console') {
            // Quick check: fetch up to 2 owners to determine if only one exists
            $ownersCount = $dbForProject->count(
                collection: 'memberships',
                queries: [
                    Query::contains('roles', ['owner']),
                    Query::equal('teamInternalId', [$team->getSequence()])
                ],
                max: 2
            );

            // Is the role change being requested by the user on their own membership?
            $isCurrentUserAnOwner =  $user->getSequence() === $membership->getAttribute('userInternalId');

            // Prevent role change if there's only one owner left,
            // the requester is that owner, and the new `$roles` no longer include 'owner'
            if ($ownersCount === 1 && $isOwner && $isCurrentUserAnOwner && !\in_array('owner', $roles)) {
                throw new Exception(Exception::MEMBERSHIP_DOWNGRADE_PROHIBITED, 'There must be at least one owner in the organization.');
            }
        }

        if (!$isOwner && !$isPrivilegedUser && !$isAppUser) { // Not owner, not admin, not app (server)
            throw new Exception(Exception::USER_UNAUTHORIZED, 'User is not allowed to modify roles');
        }

        /**
         * Update the roles
         */
        $membership->setAttribute('roles', $roles);
        $membership = $dbForProject->updateDocument('memberships', $membership->getId(), $membership);

        /**
         * Replace membership on profile
         */
        $dbForProject->purgeCachedDocument('users', $profile->getId());

        $queueForEvents
            ->setParam('userId', $profile->getId())
            ->setParam('teamId', $team->getId())
            ->setParam('membershipId', $membership->getId());

        $response->dynamic(
            $membership
                ->setAttribute('teamName', $team->getAttribute('name'))
                ->setAttribute('userName', $profile->getAttribute('name'))
                ->setAttribute('userEmail', $profile->getAttribute('email')),
            Response::MODEL_MEMBERSHIP
        );
    });

App::patch('/v1/teams/:teamId/memberships/:membershipId/status')
    ->desc('Update team membership status')
    ->groups(['api', 'teams'])
    ->label('event', 'teams.[teamId].memberships.[membershipId].update.status')
    ->label('scope', 'public')
    ->label('audits.event', 'membership.update')
    ->label('audits.resource', 'team/{request.teamId}')
    ->label('audits.userId', '{request.userId}')
    ->label('sdk', new Method(
        namespace: 'teams',
        group: 'memberships',
        name: 'updateMembershipStatus',
        description: '/docs/references/teams/update-team-membership-status.md',
        auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_MEMBERSHIP,
            )
        ]
    ))
    ->param('teamId', '', new UID(), 'Team ID.')
    ->param('membershipId', '', new UID(), 'Membership ID.')
    ->param('userId', '', new UID(), 'User ID.')
    ->param('secret', '', new Text(256), 'Secret key.')
    ->inject('request')
    ->inject('response')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('authorization')
    ->inject('project')
    ->inject('geodb')
    ->inject('queueForEvents')
    ->inject('store')
    ->inject('proofForToken')
    ->action(function (string $teamId, string $membershipId, string $userId, string $secret, Request $request, Response $response, Document $user, Database $dbForProject, Authorization $authorization, $project, Reader $geodb, Event $queueForEvents, Store $store, Token $proofForToken) {
        $protocol = $request->getProtocol();

        $membership = $dbForProject->getDocument('memberships', $membershipId);

        if ($membership->isEmpty()) {
            throw new Exception(Exception::MEMBERSHIP_NOT_FOUND);
        }

        $team = $authorization->skip(fn () => $dbForProject->getDocument('teams', $teamId));

        if ($team->isEmpty()) {
            throw new Exception(Exception::TEAM_NOT_FOUND);
        }

        if ($membership->getAttribute('teamInternalId') !== $team->getSequence()) {
            throw new Exception(Exception::TEAM_MEMBERSHIP_MISMATCH);
        }

        if (!$proofForToken->verify($secret, $membership->getAttribute('secret'))) {
            throw new Exception(Exception::TEAM_INVALID_SECRET);
        }

        if ($userId !== $membership->getAttribute('userId')) {
            throw new Exception(Exception::TEAM_INVITE_MISMATCH, 'Invite does not belong to current user (' . $user->getAttribute('email') . ')');
        }

        $hasSession = !$user->isEmpty();
        if (!$hasSession) {
            $user->setAttributes($dbForProject->getDocument('users', $userId)->getArrayCopy()); // Get user
        }

        if ($membership->getAttribute('userInternalId') !== $user->getSequence()) {
            throw new Exception(Exception::TEAM_INVITE_MISMATCH, 'Invite does not belong to current user (' . $user->getAttribute('email') . ')');
        }

        if ($membership->getAttribute('confirm') === true) {
            throw new Exception(Exception::MEMBERSHIP_ALREADY_CONFIRMED);
        }

        $membership // Attach user to team
            ->setAttribute('joined', DateTime::now())
            ->setAttribute('confirm', true)
        ;

        $authorization->skip(fn () => $dbForProject->updateDocument('users', $user->getId(), $user->setAttribute('emailVerification', true)));

        // Create session for the user if not logged in
        if (!$hasSession) {
            $authorization->addRole(Role::user($user->getId())->toString());

            $detector = new Detector($request->getUserAgent('UNKNOWN'));
            $record = $geodb->get($request->getIP());
            $authDuration = $project->getAttribute('auths', [])['duration'] ?? TOKEN_EXPIRATION_LOGIN_LONG;
            $expire = DateTime::addSeconds(new \DateTime(), $authDuration);
            $secret = $proofForToken->generate();
            $session = new Document(array_merge([
                '$id' => ID::unique(),
                '$permissions' => [
                    Permission::read(Role::user($user->getId())),
                    Permission::update(Role::user($user->getId())),
                    Permission::delete(Role::user($user->getId())),
                ],
                'userId' => $user->getId(),
                'userInternalId' => $user->getSequence(),
                'provider' => SESSION_PROVIDER_EMAIL,
                'providerUid' => $user->getAttribute('email'),
                'secret' => $proofForToken->hash($secret), // One way hash encryption to protect DB leak
                'userAgent' => $request->getUserAgent('UNKNOWN'),
                'ip' => $request->getIP(),
                'factors' => ['email'],
                'countryCode' => ($record) ? \strtolower($record['country']['iso_code']) : '--',
                'expire' => DateTime::addSeconds(new \DateTime(), $authDuration)
            ], $detector->getOS(), $detector->getClient(), $detector->getDevice()));

            $session = $dbForProject->createDocument('sessions', $session);

            $authorization->addRole(Role::user($userId)->toString());

            $encoded = $store
                ->setProperty('id', $user->getId())
                ->setProperty('secret', $secret)
                ->encode();

            if (!Config::getParam('domainVerification')) {
                $response->addHeader('X-Fallback-Cookies', \json_encode([$store->getKey() => $encoded]));
            }

            $response
                ->addCookie(
                    name: $store->getKey() . '_legacy',
                    value: $encoded,
                    expire: (new \DateTime($expire))->getTimestamp(),
                    path: '/',
                    domain: Config::getParam('cookieDomain'),
                    secure: ('https' === $protocol),
                    httponly: true
                )
                ->addCookie(
                    name: $store->getKey(),
                    value: $encoded,
                    expire: (new \DateTime($expire))->getTimestamp(),
                    path: '/',
                    domain: Config::getParam('cookieDomain'),
                    secure: ('https' === $protocol),
                    httponly: true,
                    sameSite: Config::getParam('cookieSamesite')
                )
            ;
        }

        $membership = $dbForProject->updateDocument('memberships', $membership->getId(), $membership);

        $dbForProject->purgeCachedDocument('users', $user->getId());

        $authorization->skip(fn () => $dbForProject->increaseDocumentAttribute('teams', $team->getId(), 'total', 1));

        $queueForEvents
            ->setParam('userId', $user->getId())
            ->setParam('teamId', $team->getId())
            ->setParam('membershipId', $membership->getId())
        ;

        $response->dynamic(
            $membership
                ->setAttribute('teamName', $team->getAttribute('name'))
                ->setAttribute('userName', $user->getAttribute('name'))
                ->setAttribute('userEmail', $user->getAttribute('email')),
            Response::MODEL_MEMBERSHIP
        );
    });

App::delete('/v1/teams/:teamId/memberships/:membershipId')
    ->desc('Delete team membership')
    ->groups(['api', 'teams'])
    ->label('event', 'teams.[teamId].memberships.[membershipId].delete')
    ->label('scope', 'teams.write')
    ->label('audits.event', 'membership.delete')
    ->label('audits.resource', 'team/{request.teamId}')
    ->label('sdk', new Method(
        namespace: 'teams',
        group: 'memberships',
        name: 'deleteMembership',
        description: '/docs/references/teams/delete-team-membership.md',
        auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_NOCONTENT,
                model: Response::MODEL_NONE,
            )
        ],
        contentType: ContentType::NONE
    ))
    ->param('teamId', '', new UID(), 'Team ID.')
    ->param('membershipId', '', new UID(), 'Membership ID.')
    ->inject('user')
    ->inject('project')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('authorization')
    ->inject('queueForEvents')
    ->action(function (string $teamId, string $membershipId, Document $user, Document $project, Response $response, Database $dbForProject, Authorization $authorization, Event $queueForEvents) {

        $membership = $dbForProject->getDocument('memberships', $membershipId);

        if ($membership->isEmpty()) {
            throw new Exception(Exception::TEAM_INVITE_NOT_FOUND);
        }

        $profile = $dbForProject->getDocument('users', $membership->getAttribute('userId'));

        if ($profile->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $team = $dbForProject->getDocument('teams', $teamId);

        if ($team->isEmpty()) {
            throw new Exception(Exception::TEAM_NOT_FOUND);
        }

        if ($membership->getAttribute('teamInternalId') !== $team->getSequence()) {
            throw new Exception(Exception::TEAM_MEMBERSHIP_MISMATCH);
        }

        if ($project->getId() === 'console') {
            // Quick check:
            // fetch up to 2 owners to determine if only one exists
            $ownersCount = $dbForProject->count(
                collection: 'memberships',
                queries: [
                    Query::contains('roles', ['owner']),
                    Query::equal('teamInternalId', [$team->getSequence()])
                ],
                max: 2
            );

            // Is the deletion being requested by the user on their own membership and they are also the owner?
            $isSelfOwner =
                in_array('owner', $membership->getAttribute('roles')) &&
                $membership->getAttribute('userInternalId') === $user->getSequence();

            if ($ownersCount === 1 && $isSelfOwner) {
                /* Prevent removal if the user is the only owner. */
                throw new Exception(Exception::MEMBERSHIP_DELETION_PROHIBITED, 'There must be at least one owner in the organization.');
            }
        }

        try {
            $dbForProject->deleteDocument('memberships', $membership->getId());
        } catch (AuthorizationException $exception) {
            throw new Exception(Exception::USER_UNAUTHORIZED);
        } catch (\Throwable $exception) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove membership from DB');
        }

        $dbForProject->purgeCachedDocument('users', $profile->getId());

        if ($membership->getAttribute('confirm')) { // Count only confirmed members
            $authorization->skip(fn () => $dbForProject->decreaseDocumentAttribute('teams', $team->getId(), 'total', 1, 0));
        }

        $queueForEvents
            ->setParam('teamId', $team->getId())
            ->setParam('userId', $profile->getId())
            ->setParam('membershipId', $membership->getId())
            ->setPayload($response->output($membership, Response::MODEL_MEMBERSHIP))
        ;

        $response->noContent();
    });

App::get('/v1/teams/:teamId/logs')
    ->desc('List team logs')
    ->groups(['api', 'teams'])
    ->label('scope', 'teams.read')
    ->label('sdk', new Method(
        namespace: 'teams',
        group: 'logs',
        name: 'listLogs',
        description: '/docs/references/teams/get-team-logs.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_LOG_LIST,
            )
        ]
    ))
    ->param('teamId', '', new UID(), 'Team ID.')
    ->param('queries', [], new Queries([new Limit(), new Offset()]), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Only supported methods are limit and offset', true)
    ->param('total', true, new Boolean(true), 'When set to false, the total count returned will be 0 and will not be calculated.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('locale')
    ->inject('geodb')
    ->action(function (string $teamId, array $queries, bool $includeTotal, Response $response, Database $dbForProject, Locale $locale, Reader $geodb) {

        $team = $dbForProject->getDocument('teams', $teamId);

        if ($team->isEmpty()) {
            throw new Exception(Exception::TEAM_NOT_FOUND);
        }

        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        $audit = new Audit($dbForProject);
        $resource = 'team/' . $team->getId();
        $logs = $audit->getLogsByResource($resource, $queries);

        $output = [];

        foreach ($logs as $i => &$log) {
            $log['userAgent'] = (!empty($log['userAgent'])) ? $log['userAgent'] : 'UNKNOWN';

            $detector = new Detector($log['userAgent']);
            $detector->skipBotDetection(); // OPTIONAL: If called, bot detection will completely be skipped (bots will be detected as regular devices then)

            $os = $detector->getOS();
            $client = $detector->getClient();
            $device = $detector->getDevice();

            $output[$i] = new Document([
                'event' => $log['event'],
                'userId' => $log['data']['userId'],
                'userEmail' => $log['data']['userEmail'] ?? null,
                'userName' => $log['data']['userName'] ?? null,
                'mode' => $log['data']['mode'] ?? null,
                'ip' => $log['ip'],
                'time' => $log['time'],
                'osCode' => $os['osCode'],
                'osName' => $os['osName'],
                'osVersion' => $os['osVersion'],
                'clientType' => $client['clientType'],
                'clientCode' => $client['clientCode'],
                'clientName' => $client['clientName'],
                'clientVersion' => $client['clientVersion'],
                'clientEngine' => $client['clientEngine'],
                'clientEngineVersion' => $client['clientEngineVersion'],
                'deviceName' => $device['deviceName'],
                'deviceBrand' => $device['deviceBrand'],
                'deviceModel' => $device['deviceModel']
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
            'total' => $includeTotal ? $audit->countLogsByResource($resource, $queries) : 0,
            'logs' => $output,
        ]), Response::MODEL_LOG_LIST);
    });
