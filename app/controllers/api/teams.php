<?php

use Utopia\App;
use Utopia\Exception;
use Utopia\Config\Config;
use Appwrite\Network\Validator\Email;
use Utopia\Validator\Text;
use Appwrite\Network\Validator\Host;
use Utopia\Validator\Range;
use Utopia\Validator\ArrayList;
use Utopia\Validator\WhiteList;
use Appwrite\Auth\Auth;
use Appwrite\Database\Database;
use Appwrite\Database\Document;
use Appwrite\Database\Validator\UID;
use Appwrite\Database\Validator\Authorization;
use Appwrite\Database\Exception\Duplicate;
use Appwrite\Database\Validator\Key;
use Appwrite\Detector\Detector;
use Appwrite\Template\Template;
use Appwrite\Utopia\Response;

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
    ->param('name', null, new Text(128), 'The name of the team. Max length: 128 chars.')
    ->param('roles', ['owner'], new ArrayList(new Key()), 'Array of strings. Use this param to set the roles in the team for the user who created it. The default role is **owner**. A role can be any string. Learn more about [roles and permissions](/docs/permissions). Max length for each role is 32 chars.', true)
    ->inject('response')
    ->inject('user')
    ->inject('projectDB')
    ->inject('events')
    ->action(function ($name, $roles, $response, $user, $projectDB, $events) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Document $user */
        /** @var Appwrite\Database\Database $projectDB */
        /** @var Appwrite\Event\Event $events */

        Authorization::disable();

        $isPrivilegedUser = Auth::isPrivilegedUser(Authorization::$roles);
        $isAppUser = Auth::isAppUser(Authorization::$roles);

        $team = $projectDB->createDocument([
            '$collection' => Database::SYSTEM_COLLECTION_TEAMS,
            '$permissions' => [
                'read' => ['team:{self}'],
                'write' => ['team:{self}/owner'],
            ],
            'name' => $name,
            'sum' => ($isPrivilegedUser || $isAppUser) ? 0 : 1,
            'dateCreated' => \time(),
        ]);

        Authorization::reset();

        if (false === $team) {
            throw new Exception('Failed saving team to DB', 500);
        }

        if (!$isPrivilegedUser && !$isAppUser) { // Don't add user on server mode
            $membership = new Document([
                '$collection' => Database::SYSTEM_COLLECTION_MEMBERSHIPS,
                '$permissions' => [
                    'read' => ['user:'.$user->getId(), 'team:'.$team->getId()],
                    'write' => ['user:'.$user->getId(), 'team:'.$team->getId().'/owner'],
                ],
                'userId' => $user->getId(),
                'teamId' => $team->getId(),
                'roles' => $roles,
                'invited' => \time(),
                'joined' => \time(),
                'confirm' => true,
                'secret' => '',
            ]);

            // Attach user to team
            $user->setAttribute('memberships', $membership, Document::SET_TYPE_APPEND);

            $user = $projectDB->updateDocument($user->getArrayCopy());

            if (false === $user) {
                throw new Exception('Failed saving user to DB', 500);
            }
        }

        if (!empty($user->getId())) {
            $events->setParam('userId', $user->getId());
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($team, Response::MODEL_TEAM)
        ;
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
    ->param('search', '', new Text(256), 'Enter any text to search. Max length: 256 chars.', true)
    ->param('limit', 25, new Range(0, 100), 'Limit how many results will be returned. Returns up to 25 results by default. Maximum of 100 results allowed per request.', true)
    ->param('offset', 0, new Range(0, 2000), 'Use this value to manage pagination. The default value is 0.', true)
    ->param('orderType', 'ASC', new WhiteList(['ASC', 'DESC'], true), 'Use ASC to order results in ascending and DESC to order results in descending order.', true)
    ->inject('response')
    ->inject('projectDB')
    ->action(function ($search, $limit, $offset, $orderType, $response, $projectDB) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Database $projectDB */

        $results = $projectDB->getCollection([
            'limit' => $limit,
            'offset' => $offset,
            'orderType' => $orderType,
            'search' => $search,
            'filters' => [
                '$collection='.Database::SYSTEM_COLLECTION_TEAMS,
            ],
        ]);

        $response->dynamic(new Document([
            'sum' => $projectDB->getSum(),
            'teams' => $results
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
    ->param('teamId', '', new UID(), 'The unique team ID.')
    ->inject('response')
    ->inject('projectDB')
    ->action(function ($teamId, $response, $projectDB) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Database $projectDB */

        $team = $projectDB->getDocument($teamId);

        if (empty($team->getId()) || Database::SYSTEM_COLLECTION_TEAMS != $team->getCollection()) {
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
    ->param('teamId', '', new UID(), 'The unique team ID.')
    ->param('name', null, new Text(128), 'The new team name. Max length: 128 chars.')
    ->inject('response')
    ->inject('projectDB')
    ->action(function ($teamId, $name, $response, $projectDB) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Database $projectDB */

        $team = $projectDB->getDocument($teamId);

        if (empty($team->getId()) || Database::SYSTEM_COLLECTION_TEAMS != $team->getCollection()) {
            throw new Exception('Team not found', 404);
        }

        $team = $projectDB->updateDocument(\array_merge($team->getArrayCopy(), [
            'name' => $name,
        ]));

        if (false === $team) {
            throw new Exception('Failed saving team to DB', 500);
        }
        
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
    ->param('teamId', '', new UID(), 'The unique team ID.')
    ->inject('response')
    ->inject('projectDB')
    ->inject('events')
    ->inject('deletes')
    ->action(function ($teamId, $response, $projectDB, $events, $deletes) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Database $projectDB */
        /** @var Appwrite\Event\Event $events */

        $team = $projectDB->getDocument($teamId);

        if (empty($team->getId()) || Database::SYSTEM_COLLECTION_TEAMS != $team->getCollection()) {
            throw new Exception('Team not found', 404);
        }

        if (!$projectDB->deleteDocument($teamId)) {
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
    ->param('teamId', '', new UID(), 'The unique team ID.')
    ->param('email', '', new Email(), 'The email address of the new team member.')
    ->param('roles', [], new ArrayList(new Key()), 'An Array of strings. Use this param to set the user roles in the team. A role can be any string. Learn more about [roles and permissions](/docs/permissions). Max length for each role is 32 chars.')
    ->param('url', '', function ($clients) { return new Host($clients); }, 'URL to redirect the user back to your app from the invitation email.  Only URLs from hostnames in your project platform list are allowed. This requirement helps to prevent an [open redirect](https://cheatsheetseries.owasp.org/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.html) attack against your project API.', false, ['clients']) // TODO add our own built-in confirm page
    ->param('name', '', new Text(128), 'The name of the new team member. Max length: 128 chars.', true)
    ->inject('response')
    ->inject('project')
    ->inject('user')
    ->inject('projectDB')
    ->inject('locale')
    ->inject('audits')
    ->inject('mails')
    ->action(function ($teamId, $email, $roles, $url, $name, $response, $project, $user, $projectDB, $locale, $audits, $mails) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Document $project */
        /** @var Appwrite\Database\Document $user */
        /** @var Appwrite\Database\Database $projectDB */
        /** @var Appwrite\Event\Event $audits */
        /** @var Appwrite\Event\Event $mails */

        if(empty(App::getEnv('_APP_SMTP_HOST'))) {
            throw new Exception('SMTP Disabled', 503);
        }
        
        $isPrivilegedUser = Auth::isPrivilegedUser(Authorization::$roles);
        $isAppUser = Auth::isAppUser(Authorization::$roles);
        
        $email = \strtolower($email);
        $name = (empty($name)) ? $email : $name;
        $team = $projectDB->getDocument($teamId);

        if (empty($team->getId()) || Database::SYSTEM_COLLECTION_TEAMS != $team->getCollection()) {
            throw new Exception('Team not found', 404);
        }

        $memberships = $projectDB->getCollection([
            'limit' => 2000,
            'offset' => 0,
            'filters' => [
                '$collection='.Database::SYSTEM_COLLECTION_MEMBERSHIPS,
                'teamId='.$team->getId(),
            ],
        ]);

        $invitee = $projectDB->getCollectionFirst([ // Get user by email address
            'limit' => 1,
            'filters' => [
                '$collection='.Database::SYSTEM_COLLECTION_USERS,
                'email='.$email,
            ],
        ]);

        if (empty($invitee)) { // Create new user if no user with same email found

            $limit = $project->getAttribute('usersAuthLimit', 0);
        
            if ($limit !== 0 && $project->getId() !== 'console') { // check users limit, console invites are allways allowed.
                $projectDB->getCollection([ // Count users
                    'filters' => [
                        '$collection='.Database::SYSTEM_COLLECTION_USERS,
                    ],
                ]);
    
                $sum = $projectDB->getSum();
    
                if($sum >= $limit) {
                    throw new Exception('Project registration is restricted. Contact your administrator for more information.', 501);
                }
            }

            Authorization::disable();

            try {
                $invitee = $projectDB->createDocument([
                    '$collection' => Database::SYSTEM_COLLECTION_USERS,
                    '$permissions' => [
                        'read' => ['user:{self}', '*'],
                        'write' => ['user:{self}'],
                    ],
                    'email' => $email,
                    'emailVerification' => false,
                    'status' => Auth::USER_STATUS_UNACTIVATED,
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
                    'sessions' => [],
                    'tokens' => [],
                ], ['email' => $email]);
            } catch (Duplicate $th) {
                throw new Exception('Account already exists', 409);
            }

            Authorization::reset();

            if (false === $invitee) {
                throw new Exception('Failed saving user to DB', 500);
            }
        }

        $isOwner = false;

        foreach ($memberships as $member) {
            if ($member->getAttribute('userId') ==  $invitee->getId()) {
                throw new Exception('User has already been invited or is already a member of this team', 409);
            }

            if ($member->getAttribute('userId') == $user->getId() && \in_array('owner', $member->getAttribute('roles', []))) {
                $isOwner = true;
            }
        }

        if (!$isOwner && !$isPrivilegedUser && !$isAppUser) { // Not owner, not admin, not app (server)
            throw new Exception('User is not allowed to send invitations for this team', 401);
        }

        $secret = Auth::tokenGenerator();

        $membership = new Document([
            '$collection' => Database::SYSTEM_COLLECTION_MEMBERSHIPS,
            '$permissions' => [
                'read' => ['*'],
                'write' => ['user:'.$invitee->getId(), 'team:'.$team->getId().'/owner'],
            ],
            'userId' => $invitee->getId(),
            'teamId' => $team->getId(),
            'roles' => $roles,
            'invited' => \time(),
            'joined' => ($isPrivilegedUser || $isAppUser) ? \time() : 0,
            'confirm' => ($isPrivilegedUser || $isAppUser),
            'secret' => Auth::hash($secret),
        ]);

        if ($isPrivilegedUser || $isAppUser) { // Allow admin to create membership
            Authorization::disable();
            $membership = $projectDB->createDocument($membership->getArrayCopy());

            $team = $projectDB->updateDocument(\array_merge($team->getArrayCopy(), [
                'sum' => $team->getAttribute('sum', 0) + 1,
            ]));

            // Attach user to team
            $invitee->setAttribute('memberships', $membership, Document::SET_TYPE_APPEND);

            $invitee = $projectDB->updateDocument($invitee->getArrayCopy());

            if (false === $invitee) {
                throw new Exception('Failed saving user to DB', 500);
            }

            Authorization::reset();
        } else {
            $membership = $projectDB->createDocument($membership->getArrayCopy());
        }

        if (false === $membership) {
            throw new Exception('Failed saving membership to DB', 500);
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
            ->setParam('resource', 'teams/'.$teamId)
        ;

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic(new Document(\array_merge($membership->getArrayCopy(), [
                'email' => $email,
                'name' => $name,
            ])), Response::MODEL_MEMBERSHIP)
        ;
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
    ->param('teamId', '', new UID(), 'The unique team ID.')
    ->param('membershipId', '', new UID(), 'The membership ID.')
    ->param('roles', [], new ArrayList(new Key()), 'An array of strings. Use this param to set the user\'s roles in the team. A role can be any string. Learn more about [roles and permissions](/docs/permissions). Max length for each role is 32 chars.')
    ->inject('request')
    ->inject('response')
    ->inject('user')
    ->inject('projectDB')
    ->inject('audits')
    ->action(function ($teamId, $membershipId, $roles, $request, $response, $user, $projectDB,$audits) {
        /** @var Utopia\Swoole\Request $request */
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Document $user */
        /** @var Appwrite\Database\Database $projectDB */
        /** @var Appwrite\Event\Event $audits */

        $team = $projectDB->getDocument($teamId);
        if (empty($team->getId()) || Database::SYSTEM_COLLECTION_TEAMS != $team->getCollection()) {
            throw new Exception('Team not found', 404);
        }

        $membership = $projectDB->getDocument($membershipId);
        if (empty($membership->getId()) || Database::SYSTEM_COLLECTION_MEMBERSHIPS != $membership->getCollection()) {
            throw new Exception('Membership not found', 404);
        }


        $isPrivilegedUser = Auth::isPrivilegedUser(Authorization::$roles);
        $isAppUser = Auth::isAppUser(Authorization::$roles);
        $isOwner = Authorization::isRole('team:'.$team->getId().'/owner');;
        
        if (!$isOwner && !$isPrivilegedUser && !$isAppUser) { // Not owner, not admin, not app (server)
            throw new Exception('User is not allowed to modify roles', 401);
        }

        // Update the roles
        $membership->setAttribute('roles', $roles);
        $membership = $projectDB->updateDocument($membership->getArrayCopy());

        if (false === $membership) {
            throw new Exception('Failed updating membership', 500);
        }

        $audits
            ->setParam('userId', $user->getId())
            ->setParam('event', 'teams.memberships.update')
            ->setParam('resource', 'teams/'.$teamId)
        ;

        $response->dynamic(new Document($membership->getArrayCopy()), Response::MODEL_MEMBERSHIP);
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
    ->param('teamId', '', new UID(), 'The unique team ID.')
    ->param('search', '', new Text(256), 'Search term to filter your results. Max length: 256 chars.', true)
    ->param('limit', 25, new Range(0, 100), 'Limit how many results will be returned. By default will return a maximum of 25 results. Maximum of 100 results allowed per request.', true)
    ->param('offset', 0, new Range(0, 2000), 'Results offset. The default value is 0. Use this param to manage pagination.', true)
    ->param('orderType', 'ASC', new WhiteList(['ASC', 'DESC'], true), 'Order results by ASC or DESC order.', true)
    ->inject('response')
    ->inject('projectDB')
    ->action(function ($teamId, $search, $limit, $offset, $orderType, $response, $projectDB) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Database $projectDB */

        $team = $projectDB->getDocument($teamId);

        if (empty($team->getId()) || Database::SYSTEM_COLLECTION_TEAMS != $team->getCollection()) {
            throw new Exception('Team not found', 404);
        }

        $memberships = $projectDB->getCollection([
            'limit' => $limit,
            'offset' => $offset,
            'orderType' => $orderType,
            'search' => $search,
            'filters' => [
                '$collection='.Database::SYSTEM_COLLECTION_MEMBERSHIPS,
                'teamId='.$teamId,
            ],
        ]);
        $users = [];

        foreach ($memberships as $membership) {
            if (empty($membership->getAttribute('userId', null))) {
                continue;
            }

            $temp = $projectDB->getDocument($membership->getAttribute('userId', null))->getArrayCopy(['email', 'name']);

            $users[] = new Document(\array_merge($temp, $membership->getArrayCopy()));
        }

        $response->dynamic(new Document(['sum' => $projectDB->getSum(), 'memberships' => $users]), Response::MODEL_MEMBERSHIP_LIST);
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
    ->param('teamId', '', new UID(), 'The unique team ID.')
    ->param('membershipId', '', new UID(), 'The membership ID.')
    ->param('userId', '', new UID(), 'The unique user ID.')
    ->param('secret', '', new Text(256), 'The secret key.')
    ->inject('request')
    ->inject('response')
    ->inject('user')
    ->inject('projectDB')
    ->inject('geodb')
    ->inject('audits')
    ->action(function ($teamId, $membershipId, $userId, $secret, $request, $response, $user, $projectDB, $geodb, $audits) {
        /** @var Utopia\Swoole\Request $request */
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Document $user */
        /** @var Appwrite\Database\Database $projectDB */
        /** @var MaxMind\Db\Reader $geodb */
        /** @var Appwrite\Event\Event $audits */

        $protocol = $request->getProtocol();
        $membership = $projectDB->getDocument($membershipId);

        if (empty($membership->getId()) || Database::SYSTEM_COLLECTION_MEMBERSHIPS != $membership->getCollection()) {
            throw new Exception('Invite not found', 404);
        }

        if ($membership->getAttribute('teamId') !== $teamId) {
            throw new Exception('Team IDs don\'t match', 404);
        }

        Authorization::disable();

        $team = $projectDB->getDocument($teamId);
        
        Authorization::reset();

        if (empty($team->getId()) || Database::SYSTEM_COLLECTION_TEAMS != $team->getCollection()) {
            throw new Exception('Team not found', 404);
        }

        if (Auth::hash($secret) !== $membership->getAttribute('secret')) {
            throw new Exception('Secret key not valid', 401);
        }

        if ($userId != $membership->getAttribute('userId')) {
            throw new Exception('Invite does not belong to current user ('.$user->getAttribute('email').')', 401);
        }

        if (empty($user->getId())) {
            $user = $projectDB->getCollectionFirst([ // Get user
                'limit' => 1,
                'filters' => [
                    '$collection='.Database::SYSTEM_COLLECTION_USERS,
                    '$id='.$userId,
                ],
            ]);
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

        $detector = new Detector($request->getUserAgent('UNKNOWN'));
        $record = $geodb->get($request->getIP());
        $expiry = \time() + Auth::TOKEN_EXPIRATION_LOGIN_LONG;
        $secret = Auth::tokenGenerator();
        $session = new Document(array_merge([
            '$collection' => Database::SYSTEM_COLLECTION_SESSIONS,
            '$permissions' => ['read' => ['user:'.$user->getId()], 'write' => ['user:'.$user->getId()]],
            'userId' => $user->getId(),
            'provider' => Auth::SESSION_PROVIDER_EMAIL,
            'providerUid' => $user->getAttribute('email'),
            'secret' => Auth::hash($secret), // One way hash encryption to protect DB leak
            'expire' => $expiry,
            'userAgent' => $request->getUserAgent('UNKNOWN'),
            'ip' => $request->getIP(),
            'countryCode' => ($record) ? \strtolower($record['country']['iso_code']) : '--',
        ], $detector->getOS(), $detector->getClient(), $detector->getDevice()));

        $user->setAttribute('sessions', $session, Document::SET_TYPE_APPEND);

        Authorization::setRole('user:'.$userId);

        $user = $projectDB->updateDocument($user->getArrayCopy());

        if (false === $user) {
            throw new Exception('Failed saving user to DB', 500);
        }

        Authorization::disable();

        $team = $projectDB->updateDocument(\array_merge($team->getArrayCopy(), [
            'sum' => $team->getAttribute('sum', 0) + 1,
        ]));

        Authorization::reset();

        if (false === $team) {
            throw new Exception('Failed saving team to DB', 500);
        }

        $audits
            ->setParam('userId', $user->getId())
            ->setParam('event', 'teams.memberships.update.status')
            ->setParam('resource', 'teams/'.$teamId)
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

        $response->dynamic(new Document(\array_merge($membership->getArrayCopy(), [
            'email' => $user->getAttribute('email'),
            'name' => $user->getAttribute('name'),
        ])), Response::MODEL_MEMBERSHIP);
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
    ->param('teamId', '', new UID(), 'The unique team ID.')
    ->param('membershipId', '', new UID(), 'The membership ID.')
    ->inject('response')
    ->inject('projectDB')
    ->inject('audits')
    ->inject('events')
    ->action(function ($teamId, $membershipId, $response, $projectDB, $audits, $events) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Database $projectDB */
        /** @var Appwrite\Event\Event $audits */
        /** @var Appwrite\Event\Event $events */

        $membership = $projectDB->getDocument($membershipId);

        if (empty($membership->getId()) || Database::SYSTEM_COLLECTION_MEMBERSHIPS != $membership->getCollection()) {
            throw new Exception('Invite not found', 404);
        }

        if ($membership->getAttribute('teamId') !== $teamId) {
            throw new Exception('Team IDs don\'t match', 404);
        }

        $team = $projectDB->getDocument($teamId);

        if (empty($team->getId()) || Database::SYSTEM_COLLECTION_TEAMS != $team->getCollection()) {
            throw new Exception('Team not found', 404);
        }

        if (!$projectDB->deleteDocument($membership->getId())) {
            throw new Exception('Failed to remove membership from DB', 500);
        }

        if ($membership->getAttribute('confirm')) { // Count only confirmed members
            $team = $projectDB->updateDocument(\array_merge($team->getArrayCopy(), [
                'sum' => \max($team->getAttribute('sum', 0) - 1, 0), // Ensure that sum >= 0
            ]));
        }

        if (false === $team) {
            throw new Exception('Failed saving team to DB', 500);
        }

        $audits
            ->setParam('userId', $membership->getAttribute('userId'))
            ->setParam('event', 'teams.memberships.delete')
            ->setParam('resource', 'teams/'.$teamId)
        ;

        $events
            ->setParam('eventData', $response->output($membership, Response::MODEL_MEMBERSHIP))
        ;

        $response->noContent();
    });
