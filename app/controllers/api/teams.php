<?php

use Appwrite\Auth\Auth;
use Appwrite\Database\Validator\CustomId;
use Appwrite\Detector\Detector;
use Appwrite\Template\Template;
use Appwrite\Utopia\Response;
use Appwrite\Network\Validator\Email;
use Appwrite\Network\Validator\Host;
use Utopia\App;
use Utopia\Exception;
use Utopia\Config\Config;
use Utopia\Validator\Text;
use Utopia\Validator\Range;
use Utopia\Validator\ArrayList;
use Utopia\Validator\WhiteList;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Key;
use Utopia\Database\Validator\UID;

App::post('/v1/teams')
    ->desc('Create Team')
    ->groups(['api', 'teams'])
    ->label('event', 'teams.create')
    ->label('scope', 'teams.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'teams')
    ->label('sdk.method', 'create')
    ->label('sdk.description', '/docs/references/teams/create-team.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_TEAM)
    ->param('teamId', '', new CustomId(), 'Team ID. Choose your own unique ID or pass the string "unique()" to auto generate it. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('name', null, new Text(128), 'Team name. Max length: 128 chars.')
    ->param('roles', ['owner'], new ArrayList(new Key()), 'Array of strings. Use this param to set the roles in the team for the user who created it. The default role is **owner**. A role can be any string. Learn more about [roles and permissions](/docs/permissions). Max length for each role is 32 chars.', true)
    ->inject('response')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('events')
    ->action(function ($teamId, $name, $roles, $response, $user, $dbForProject, $events) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Document $user */
        /** @var Utopia\Database\Database $dbForProject */
        /** @var Appwrite\Event\Event $events */

        $isPrivilegedUser = Auth::isPrivilegedUser(Authorization::getRoles());
        $isAppUser = Auth::isAppUser(Authorization::getRoles());

        $teamId = $teamId == 'unique()' ? $dbForProject->getId() : $teamId;
        $team = Authorization::skip(fn() => $dbForProject->createDocument('teams', new Document([
            '$id' => $teamId ,
            '$read' => ['team:'.$teamId],
            '$write' => ['team:'.$teamId .'/owner'],
            'name' => $name,
            'sum' => ($isPrivilegedUser || $isAppUser) ? 0 : 1,
            'dateCreated' => \time(),
            'search' => implode(' ', [$teamId, $name]),
        ])));

        if (!$isPrivilegedUser && !$isAppUser) { // Don't add user on server mode
            $membership = new Document([
                '$read' => ['user:'.$user->getId(), 'team:'.$team->getId()],
                '$write' => ['user:'.$user->getId(), 'team:'.$team->getId().'/owner'],
                'userId' => $user->getId(),
                'teamId' => $team->getId(),
                'roles' => $roles,
                'invited' => \time(),
                'joined' => \time(),
                'confirm' => true,
                'secret' => '',
            ]);

            $membership = $dbForProject->createDocument('memberships', $membership);

            // Attach user to team
            $user->setAttribute('memberships', $membership, Document::SET_TYPE_APPEND);
            $user = $dbForProject->updateDocument('users', $user->getId(), $user);
        }

        if (!empty($user->getId())) {
            $events->setParam('userId', $user->getId());
        }

        $response->setStatusCode(Response::STATUS_CODE_CREATED);
        $response->dynamic($team, Response::MODEL_TEAM);
    });

App::get('/v1/teams')
    ->desc('List Teams')
    ->groups(['api', 'teams'])
    ->label('scope', 'teams.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'teams')
    ->label('sdk.method', 'list')
    ->label('sdk.description', '/docs/references/teams/list-teams.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_TEAM_LIST)
    ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
    ->param('limit', 25, new Range(0, 100), 'Maximum number of teams to return in response. By default will return maximum 25 results. Maximum of 100 results allowed per request.', true)
    ->param('offset', 0, new Range(0, APP_LIMIT_COUNT), 'Offset value. The default value is 0. Use this param to manage pagination. [learn more about pagination](https://appwrite.io/docs/pagination)', true)
    ->param('cursor', '', new UID(), 'ID of the team used as the starting point for the query, excluding the team itself. Should be used for efficient pagination when working with large sets of data. [learn more about pagination](https://appwrite.io/docs/pagination)', true)
    ->param('cursorDirection', Database::CURSOR_AFTER, new WhiteList([Database::CURSOR_AFTER, Database::CURSOR_BEFORE]), 'Direction of the cursor.', true)
    ->param('orderType', 'ASC', new WhiteList(['ASC', 'DESC'], true), 'Order result by ASC or DESC order.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->action(function ($search, $limit, $offset, $cursor, $cursorDirection, $orderType, $response, $dbForProject) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForProject */

        if (!empty($cursor)) {
            $cursorTeam = $dbForProject->getDocument('teams', $cursor);

            if ($cursorTeam->isEmpty()) {
                throw new Exception("Team '{$cursor}' for the 'cursor' value not found.", 400);
            }
        }

        $queries = [];

        if (!empty($search)) {
            $queries[] = new Query('search', Query::TYPE_SEARCH, [$search]);
        }

        $results = $dbForProject->find('teams', $queries, $limit, $offset, [], [$orderType], $cursorTeam ?? null, $cursorDirection);
        $sum = $dbForProject->count('teams', $queries, APP_LIMIT_COUNT);

        $response->dynamic(new Document([
            'teams' => $results,
            'sum' => $sum,
        ]), Response::MODEL_TEAM_LIST);
    });

App::get('/v1/teams/:teamId')
    ->desc('Get Team')
    ->groups(['api', 'teams'])
    ->label('scope', 'teams.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'teams')
    ->label('sdk.method', 'get')
    ->label('sdk.description', '/docs/references/teams/get-team.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_TEAM)
    ->param('teamId', '', new UID(), 'Team ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->action(function ($teamId, $response, $dbForProject) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForProject */

        $team = $dbForProject->getDocument('teams', $teamId);

        if ($team->isEmpty()) {
            throw new Exception('Team not found', 404);
        }

        $response->dynamic($team, Response::MODEL_TEAM);
    });

App::put('/v1/teams/:teamId')
    ->desc('Update Team')
    ->groups(['api', 'teams'])
    ->label('event', 'teams.update')
    ->label('scope', 'teams.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'teams')
    ->label('sdk.method', 'update')
    ->label('sdk.description', '/docs/references/teams/update-team.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_TEAM)
    ->param('teamId', '', new UID(), 'Team ID.')
    ->param('name', null, new Text(128), 'New team name. Max length: 128 chars.')
    ->inject('response')
    ->inject('dbForProject')
    ->action(function ($teamId, $name, $response, $dbForProject) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForProject */

        $team = $dbForProject->getDocument('teams', $teamId);

        if ($team->isEmpty()) {
            throw new Exception('Team not found', 404);
        }

        $team = $dbForProject->updateDocument('teams', $team->getId(),$team
            ->setAttribute('name', $name)
            ->setAttribute('search', implode(' ', [$teamId, $name]))
        );

        $response->dynamic($team, Response::MODEL_TEAM);
    });

App::delete('/v1/teams/:teamId')
    ->desc('Delete Team')
    ->groups(['api', 'teams'])
    ->label('event', 'teams.delete')
    ->label('scope', 'teams.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'teams')
    ->label('sdk.method', 'delete')
    ->label('sdk.description', '/docs/references/teams/delete-team.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('teamId', '', new UID(), 'Team ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('events')
    ->inject('deletes')
    ->action(function ($teamId, $response, $dbForProject, $events, $deletes) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForProject */
        /** @var Appwrite\Event\Event $events */
        /** @var Appwrite\Event\Event $deletes */

        $team = $dbForProject->getDocument('teams', $teamId);

        if ($team->isEmpty()) {
            throw new Exception('Team not found', 404);
        }

        $memberships = $dbForProject->find('memberships', [
            new Query('teamId', Query::TYPE_EQUAL, [$teamId]),
        ], 2000, 0); // TODO fix members limit

        // TODO delete all members individually from the user object
        foreach ($memberships as $membership) {
            if (!$dbForProject->deleteDocument('memberships', $membership->getId())) {
                throw new Exception('Failed to remove membership for team from DB', 500);
            }
        }

        if (!$dbForProject->deleteDocument('teams', $teamId)) {
            throw new Exception('Failed to remove team from DB', 500);
        }

        $deletes
            ->setParam('type', DELETE_TYPE_DOCUMENT)
            ->setParam('document', $team)
        ;

        $events
            ->setParam('eventData', $response->output($team, Response::MODEL_TEAM))
        ;

        $response->noContent();
    });

App::post('/v1/teams/:teamId/memberships')
    ->desc('Create Team Membership')
    ->groups(['api', 'teams', 'auth'])
    ->label('event', 'teams.memberships.create')
    ->label('scope', 'teams.write')
    ->label('auth.type', 'invites')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'teams')
    ->label('sdk.method', 'createMembership')
    ->label('sdk.description', '/docs/references/teams/create-team-membership.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_MEMBERSHIP)
    ->label('abuse-limit', 10)
    ->param('teamId', '', new UID(), 'Team ID.')
    ->param('email', '', new Email(), 'Email of the new team member.')
    ->param('roles', [], new ArrayList(new Key()), 'Array of strings. Use this param to set the user roles in the team. A role can be any string. Learn more about [roles and permissions](/docs/permissions). Max length for each role is 32 chars.')
    ->param('url', '', function ($clients) { return new Host($clients); }, 'URL to redirect the user back to your app from the invitation email.  Only URLs from hostnames in your project platform list are allowed. This requirement helps to prevent an [open redirect](https://cheatsheetseries.owasp.org/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.html) attack against your project API.', false, ['clients']) // TODO add our own built-in confirm page
    ->param('name', '', new Text(128), 'Name of the new team member. Max length: 128 chars.', true)
    ->inject('response')
    ->inject('project')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('locale')
    ->inject('audits')
    ->inject('mails')
    ->action(function ($teamId, $email, $roles, $url, $name, $response, $project, $user, $dbForProject, $locale, $audits, $mails) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Document $project */
        /** @var Utopia\Database\Document $user */
        /** @var Utopia\Database\Database $dbForProject */
        /** @var Appwrite\Event\Event $audits */
        /** @var Appwrite\Event\Event $mails */

        if(empty(App::getEnv('_APP_SMTP_HOST'))) {
            throw new Exception('SMTP Disabled', 503);
        }

        $isPrivilegedUser = Auth::isPrivilegedUser(Authorization::getRoles());
        $isAppUser = Auth::isAppUser(Authorization::getRoles());

        $email = \strtolower($email);
        $name = (empty($name)) ? $email : $name;
        $team = $dbForProject->getDocument('teams', $teamId);

        if ($team->isEmpty()) {
            throw new Exception('Team not found', 404);
        }

        $invitee = $dbForProject->findOne('users', [new Query('email', Query::TYPE_EQUAL, [$email])]); // Get user by email address

        if (empty($invitee)) { // Create new user if no user with same email found

            $limit = $project->getAttribute('auths', [])['limit'] ?? 0;

            if ($limit !== 0 && $project->getId() !== 'console') { // check users limit, console invites are allways allowed.
                $sum = $dbForProject->count('users', [], APP_LIMIT_USERS);

                if($sum >= $limit) {
                    throw new Exception('Project registration is restricted. Contact your administrator for more information.', 501);
                }
            }

            try {
                $userId = $dbForProject->getId();
                $invitee = Authorization::skip(fn() => $dbForProject->createDocument('users', new Document([
                    '$id' => $userId,
                    '$read' => ['user:'.$userId, 'role:all'],
                    '$write' => ['user:'.$userId],
                    'email' => $email,
                    'emailVerification' => false,
                    'status' => true,
                    'password' => Auth::passwordHash(Auth::passwordGenerator()),
                    /** 
                     * Set the password update time to 0 for users created using 
                     * team invite and OAuth to allow password updates without an 
                     * old password 
                     */
                    'passwordUpdate' => 0,
                    'registration' => \time(),
                    'reset' => false,
                    'name' => $name,
                    'prefs' => new \stdClass(),
                    'sessions' => [],
                    'tokens' => [],
                    'memberships' => [],
                    'search' => implode(' ', [$userId, $email, $name]),
                ])));
            } catch (Duplicate $th) {
                throw new Exception('Account already exists', 409);
            }
        }

        $isOwner = Authorization::isRole('team:'.$team->getId().'/owner');;

        if (!$isOwner && !$isPrivilegedUser && !$isAppUser) { // Not owner, not admin, not app (server)
            throw new Exception('User is not allowed to send invitations for this team', 401);
        }

        $secret = Auth::tokenGenerator();

        $membership = new Document([
            '$id' => $dbForProject->getId(),
            '$read' => ['role:all'],
            '$write' => ['user:'.$invitee->getId(), 'team:'.$team->getId().'/owner'],
            'userId' => $invitee->getId(),
            'teamId' => $team->getId(),
            'roles' => $roles,
            'invited' => \time(),
            'joined' => ($isPrivilegedUser || $isAppUser) ? \time() : 0,
            'confirm' => ($isPrivilegedUser || $isAppUser),
            'secret' => Auth::hash($secret),
        ]);

        if ($isPrivilegedUser || $isAppUser) { // Allow admin to create membership
            try {
                $membership = Authorization::skip(fn() => $dbForProject->createDocument('memberships', $membership));
            } catch (Duplicate $th) {
                throw new Exception('User has already been invited or is already a member of this team', 409);
            }
            $team->setAttribute('sum', $team->getAttribute('sum', 0) + 1);
            $team = Authorization::skip(fn() => $dbForProject->updateDocument('teams', $team->getId(), $team));

            // Attach user to team
            $invitee->setAttribute('memberships', $membership, Document::SET_TYPE_APPEND);

            $invitee = Authorization::skip(fn() => $dbForProject->updateDocument('users', $invitee->getId(), $invitee));
        } else {
            try {
                $membership = $dbForProject->createDocument('memberships', $membership);
            } catch (Duplicate $th) {
                throw new Exception('User has already been invited or is already a member of this team', 409);
            }
        }

        $url = Template::parseURL($url);
        $url['query'] = Template::mergeQuery(((isset($url['query'])) ? $url['query'] : ''), ['membershipId' => $membership->getId(), 'userId' => $invitee->getId(), 'secret' => $secret, 'teamId' => $teamId]);
        $url = Template::unParseURL($url);

        if (!$isPrivilegedUser && !$isAppUser) { // No need of confirmation when in admin or app mode
            $mails
                ->setParam('event', 'teams.memberships.create')
                ->setParam('from', $project->getId())
                ->setParam('recipient', $email)
                ->setParam('name', $name)
                ->setParam('url', $url)
                ->setParam('locale', $locale->default)
                ->setParam('project', $project->getAttribute('name', ['[APP-NAME]']))
                ->setParam('owner', $user->getAttribute('name', ''))
                ->setParam('team', $team->getAttribute('name', '[TEAM-NAME]'))
                ->setParam('type', MAIL_TYPE_INVITATION)
                ->trigger()
            ;
        }

        $audits
            ->setParam('userId', $invitee->getId())
            ->setParam('event', 'teams.memberships.create')
            ->setParam('resource', 'team/'.$teamId)
        ;

        $response->setStatusCode(Response::STATUS_CODE_CREATED);
        $response->dynamic($membership
            ->setAttribute('email', $email)
            ->setAttribute('name', $name)
        , Response::MODEL_MEMBERSHIP);
    });

App::get('/v1/teams/:teamId/memberships')
    ->desc('Get Team Memberships')
    ->groups(['api', 'teams'])
    ->label('scope', 'teams.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'teams')
    ->label('sdk.method', 'getMemberships')
    ->label('sdk.description', '/docs/references/teams/get-team-members.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_MEMBERSHIP_LIST)
    ->param('teamId', '', new UID(), 'Team ID.')
    ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
    ->param('limit', 25, new Range(0, 100), 'Maximum number of memberships to return in response. By default will return maximum 25 results. Maximum of 100 results allowed per request.', true)
    ->param('offset', 0, new Range(0, APP_LIMIT_COUNT), 'Offset value. The default value is 0. Use this value to manage pagination. [learn more about pagination](https://appwrite.io/docs/pagination)', true)
    ->param('cursor', '', new UID(), 'ID of the membership used as the starting point for the query, excluding the membership itself. Should be used for efficient pagination when working with large sets of data. [learn more about pagination](https://appwrite.io/docs/pagination)', true)
    ->param('cursorDirection', Database::CURSOR_AFTER, new WhiteList([Database::CURSOR_AFTER, Database::CURSOR_BEFORE]), 'Direction of the cursor.', true)
    ->param('orderType', 'ASC', new WhiteList(['ASC', 'DESC'], true), 'Order result by ASC or DESC order.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->action(function ($teamId, $search, $limit, $offset, $cursor, $cursorDirection, $orderType, $response, $dbForProject) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForProject */

        $team = $dbForProject->getDocument('teams', $teamId);

        if ($team->isEmpty()) {
            throw new Exception('Team not found', 404);
        }

        if (!empty($cursor)) {
            $cursorMembership = $dbForProject->getDocument('memberships', $cursor);

            if ($cursorMembership->isEmpty()) {
                throw new Exception("Membership '{$cursor}' for the 'cursor' value not found.", 400);
            }
        }

        $memberships = $dbForProject->find('memberships', [new Query('teamId', Query::TYPE_EQUAL, [$teamId])], $limit, $offset, [], [$orderType], $cursorMembership ?? null, $cursorDirection);
        $sum = $dbForProject->count('memberships', [new Query('teamId', Query::TYPE_EQUAL, [$teamId])], APP_LIMIT_COUNT);

        $memberships = array_filter($memberships, fn(Document $membership) => !empty($membership->getAttribute('userId')));

        $memberships = array_map(function($membership) use ($dbForProject) {
            $user = $dbForProject->getDocument('users', $membership->getAttribute('userId'));

            $membership
                ->setAttribute('name', $user->getAttribute('name'))
                ->setAttribute('email', $user->getAttribute('email'))
            ;

            return $membership;
        }, $memberships);

        $response->dynamic(new Document([
            'memberships' => $memberships,
            'sum' => $sum,
        ]), Response::MODEL_MEMBERSHIP_LIST);
    });

App::get('/v1/teams/:teamId/memberships/:membershipId')
    ->desc('Get Team Membership')
    ->groups(['api', 'teams'])
    ->label('scope', 'teams.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'teams')
    ->label('sdk.method', 'getMembership')
    ->label('sdk.description', '/docs/references/teams/get-team-member.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_MEMBERSHIP_LIST)
    ->param('teamId', '', new UID(), 'Team ID.')
    ->param('membershipId', '', new UID(), 'Membership ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->action(function ($teamId, $membershipId, $response, $dbForProject) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForProject */

        $team = $dbForProject->getDocument('teams', $teamId);

        if ($team->isEmpty()) {
            throw new Exception('Team not found', 404);
        }

        $membership = $dbForProject->getDocument('memberships', $membershipId);

        if($membership->isEmpty() || empty($membership->getAttribute('userId'))) {
            throw new Exception('Membership not found', 404);
        }

        $user = $dbForProject->getDocument('users', $membership->getAttribute('userId'));

        $membership
            ->setAttribute('name', $user->getAttribute('name'))
            ->setAttribute('email', $user->getAttribute('email'))
        ;

        $response->dynamic($membership, Response::MODEL_MEMBERSHIP );
    });

App::patch('/v1/teams/:teamId/memberships/:membershipId')
    ->desc('Update Membership Roles')
    ->groups(['api', 'teams'])
    ->label('event', 'teams.memberships.update')
    ->label('scope', 'teams.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'teams')
    ->label('sdk.method', 'updateMembershipRoles')
    ->label('sdk.description', '/docs/references/teams/update-team-membership-roles.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_MEMBERSHIP)
    ->param('teamId', '', new UID(), 'Team ID.')
    ->param('membershipId', '', new UID(), 'Membership ID.')
    ->param('roles', [], new ArrayList(new Key()), 'An array of strings. Use this param to set the user\'s roles in the team. A role can be any string. Learn more about [roles and permissions](https://appwrite.io/docs/permissions). Max length for each role is 32 chars.')
    ->inject('request')
    ->inject('response')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('audits')
    ->action(function ($teamId, $membershipId, $roles, $request, $response, $user, $dbForProject, $audits) {
        /** @var Appwrite\Utopia\Request $request */
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Document $user */
        /** @var Utopia\Database\Database $dbForProject */
        /** @var Appwrite\Event\Event $audits */

        $team = $dbForProject->getDocument('teams', $teamId);
        if ($team->isEmpty()) {
            throw new Exception('Team not found', 404);
        }

        $membership = $dbForProject->getDocument('memberships', $membershipId);
        if ($membership->isEmpty()) {
            throw new Exception('Membership not found', 404);
        }

        $profile = $dbForProject->getDocument('users', $membership->getAttribute('userId'));
        if ($profile->isEmpty()) {
            throw new Exception('User not found', 404);
        }

        $isPrivilegedUser = Auth::isPrivilegedUser(Authorization::getRoles());
        $isAppUser = Auth::isAppUser(Authorization::getRoles());
        $isOwner = Authorization::isRole('team:'.$team->getId().'/owner');;

        if (!$isOwner && !$isPrivilegedUser && !$isAppUser) { // Not owner, not admin, not app (server)
            throw new Exception('User is not allowed to modify roles', 401);
        }

        // Update the roles
        $membership->setAttribute('roles', $roles);
        $membership = $dbForProject->updateDocument('memberships', $membership->getId(), $membership);

        // TODO sync updated membership in the user $profile object using TYPE_REPLACE

        $audits
            ->setParam('userId', $user->getId())
            ->setParam('event', 'teams.memberships.update')
            ->setParam('resource', 'team/'.$teamId)
        ;

        $response->dynamic($membership, Response::MODEL_MEMBERSHIP);
    });

App::patch('/v1/teams/:teamId/memberships/:membershipId/status')
    ->desc('Update Team Membership Status')
    ->groups(['api', 'teams'])
    ->label('event', 'teams.memberships.update.status')
    ->label('scope', 'public')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'teams')
    ->label('sdk.method', 'updateMembershipStatus')
    ->label('sdk.description', '/docs/references/teams/update-team-membership-status.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_MEMBERSHIP)
    ->param('teamId', '', new UID(), 'Team ID.')
    ->param('membershipId', '', new UID(), 'Membership ID.')
    ->param('userId', '', new UID(), 'User ID.')
    ->param('secret', '', new Text(256), 'Secret key.')
    ->inject('request')
    ->inject('response')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('geodb')
    ->inject('audits')
    ->action(function ($teamId, $membershipId, $userId, $secret, $request, $response, $user, $dbForProject, $geodb, $audits) {
        /** @var Appwrite\Utopia\Request $request */
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Document $user */
        /** @var Utopia\Database\Database $dbForProject */
        /** @var MaxMind\Db\Reader $geodb */
        /** @var Appwrite\Event\Event $audits */

        $protocol = $request->getProtocol();

        $membership = $dbForProject->getDocument('memberships', $membershipId);

        if ($membership->isEmpty()) {
            throw new Exception('Membership not found', 404);
        }

        if ($membership->getAttribute('teamId') !== $teamId) {
            throw new Exception('Team IDs don\'t match', 404);
        }

        $team = Authorization::skip(fn() => $dbForProject->getDocument('teams', $teamId));

        if ($team->isEmpty()) {
            throw new Exception('Team not found', 404);
        }

        if (Auth::hash($secret) !== $membership->getAttribute('secret')) {
            throw new Exception('Secret key not valid', 401);
        }

        if ($userId != $membership->getAttribute('userId')) {
            throw new Exception('Invite does not belong to current user ('.$user->getAttribute('email').')', 401);
        }

        if ($user->isEmpty()) {
            $user = $dbForProject->getDocument('users', $userId); // Get user
        }

        if ($membership->getAttribute('userId') !== $user->getId()) {
            throw new Exception('Invite does not belong to current user ('.$user->getAttribute('email').')', 401);
        }

        $membership // Attach user to team
            ->setAttribute('joined', \time())
            ->setAttribute('confirm', true)
        ;

        $user
            ->setAttribute('emailVerification', true)
            ->setAttribute('memberships', $membership, Document::SET_TYPE_APPEND)
        ;

        // Log user in

        Authorization::setRole('user:'.$user->getId());

        $detector = new Detector($request->getUserAgent('UNKNOWN'));
        $record = $geodb->get($request->getIP());
        $expiry = \time() + Auth::TOKEN_EXPIRATION_LOGIN_LONG;
        $secret = Auth::tokenGenerator();
        $session = new Document(array_merge([
            '$id' => $dbForProject->getId(),
            'userId' => $user->getId(),
            'provider' => Auth::SESSION_PROVIDER_EMAIL,
            'providerUid' => $user->getAttribute('email'),
            'secret' => Auth::hash($secret), // One way hash encryption to protect DB leak
            'expire' => $expiry,
            'userAgent' => $request->getUserAgent('UNKNOWN'),
            'ip' => $request->getIP(),
            'countryCode' => ($record) ? \strtolower($record['country']['iso_code']) : '--',
        ], $detector->getOS(), $detector->getClient(), $detector->getDevice()));

        $session = $dbForProject->createDocument('sessions', $session
            ->setAttribute('$read', ['user:'.$user->getId()])
            ->setAttribute('$write', ['user:'.$user->getId()])
        );

        $user->setAttribute('sessions', $session, Document::SET_TYPE_APPEND);

        Authorization::setRole('user:'.$userId);

        $user = $dbForProject->updateDocument('users', $user->getId(), $user);
        $membership = $dbForProject->updateDocument('memberships', $membership->getId(), $membership);

        $team = Authorization::skip(fn() => $dbForProject->updateDocument('teams', $team->getId(), $team->setAttribute('sum', $team->getAttribute('sum', 0) + 1)));

        $audits
            ->setParam('userId', $user->getId())
            ->setParam('event', 'teams.memberships.update.status')
            ->setParam('resource', 'team/'.$teamId)
        ;

        if (!Config::getParam('domainVerification')) {
            $response
                ->addHeader('X-Fallback-Cookies', \json_encode([Auth::$cookieName => Auth::encodeSession($user->getId(), $secret)]))
            ;
        }

        $response
            ->addCookie(Auth::$cookieName.'_legacy', Auth::encodeSession($user->getId(), $secret), $expiry, '/', Config::getParam('cookieDomain'), ('https' == $protocol), true, null)
            ->addCookie(Auth::$cookieName, Auth::encodeSession($user->getId(), $secret), $expiry, '/', Config::getParam('cookieDomain'), ('https' == $protocol), true, Config::getParam('cookieSamesite'))
        ;

        $response->dynamic($membership
            ->setAttribute('email', $user->getAttribute('email'))
            ->setAttribute('name', $user->getAttribute('name'))
        , Response::MODEL_MEMBERSHIP);
    });

App::delete('/v1/teams/:teamId/memberships/:membershipId')
    ->desc('Delete Team Membership')
    ->groups(['api', 'teams'])
    ->label('event', 'teams.memberships.delete')
    ->label('scope', 'teams.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'teams')
    ->label('sdk.method', 'deleteMembership')
    ->label('sdk.description', '/docs/references/teams/delete-team-membership.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('teamId', '', new UID(), 'Team ID.')
    ->param('membershipId', '', new UID(), 'Membership ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('audits')
    ->inject('events')
    ->action(function ($teamId, $membershipId, $response, $dbForProject, $audits, $events) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForProject */
        /** @var Appwrite\Event\Event $audits */
        /** @var Appwrite\Event\Event $events */

        $membership = $dbForProject->getDocument('memberships', $membershipId);

        if ($membership->isEmpty()) {
            throw new Exception('Invite not found', 404);
        }

        if ($membership->getAttribute('teamId') !== $teamId) {
            throw new Exception('Team IDs don\'t match', 404);
        }

        $user = $dbForProject->getDocument('users', $membership->getAttribute('userId'));

        if ($user->isEmpty()) {
            throw new Exception('User not found', 404);
        }

        $team = $dbForProject->getDocument('teams', $teamId);

        if ($team->isEmpty()) {
            throw new Exception('Team not found', 404);
        }

        if (!$dbForProject->deleteDocument('memberships', $membership->getId())) {
            throw new Exception('Failed to remove membership from DB', 500);
        }

        $memberships = $user->getAttribute('memberships', []);

        foreach ($memberships as $key => $child) { 
            /** @var Document $child */

            if ($membershipId == $child->getId()) {
                unset($memberships[$key]);
                break;
            }
        }

        $user->setAttribute('memberships', $memberships);

        Authorization::skip(fn() => $dbForProject->updateDocument('users', $user->getId(), $user));

        if ($membership->getAttribute('confirm')) { // Count only confirmed members
            $team->setAttribute('sum', \max($team->getAttribute('sum', 0) - 1, 0));
            $team = $dbForProject->updateDocument('teams', $team->getId(), $team);
        }

        $audits
            ->setParam('userId', $membership->getAttribute('userId'))
            ->setParam('event', 'teams.memberships.delete')
            ->setParam('resource', 'team/'.$teamId)
        ;

        $events
            ->setParam('eventData', $response->output($membership, Response::MODEL_MEMBERSHIP))
        ;

        $response->noContent();
    });
