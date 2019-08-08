<?php

global $utopia, $register, $request, $response, $projectDB, $project, $user, $audit, $mode;

use Utopia\Exception;
use Utopia\Response;
use Utopia\Validator\Email;
use Utopia\Validator\Text;
use Utopia\Validator\Host;
use Utopia\Validator\Range;
use Utopia\Validator\ArrayList;
use Utopia\Validator\WhiteList;
use Utopia\Locale\Locale;
use Database\Database;
use Database\Document;
use Database\Validator\UID;
use Database\Validator\Authorization;
use Template\Template;
use Auth\Auth;

$utopia->get('/v1/teams')
    ->desc('List Teams')
    ->label('scope', 'teams.read')
    ->label('sdk.namespace', 'teams')
    ->label('sdk.method', 'listTeams')
    ->label('sdk.description', 'Get a list of all the current user teams. You can use the query params to filter your results. On admin mode, this endpoint will return a list of all of the project teams. [Learn more about different API modes](/docs/modes).')
    ->param('search', '', function () {return new Text(256);}, 'Search term to filter your list results.', true)
    ->param('limit', 25, function () {return new Range(0, 100);}, 'Results limit value. By default will return maximum 25 results. Maximum of 100 results allowed per request.', true)
    ->param('offset', 0 , function () {return new Range(0, 2000);}, 'Results offset. The default value is 0. Use this param to manage pagination.', true)
    ->param('orderType', 'ASC', function () {return new WhiteList(['ASC', 'DESC']);}, 'Order result by ASC or DESC order.', true)
    ->action(
        function($search, $limit, $offset, $orderType) use ($response, $projectDB)
        {
            $results = $projectDB->getCollection([
                'limit' => $limit,
                'offset' => $offset,
                'orderField' => 'dateCreated',
                'orderType' => $orderType,
                'orderCast' => 'int',
                'search' => $search,
                'filters' => [
                    '$collection=' . Database::SYSTEM_COLLECTION_TEAMS
                ],
            ]);

            $response->json(['sum' => $projectDB->getSum(), 'teams' => $results]);
        }
    );

$utopia->get('/v1/teams/:teamId')
    ->desc('Get Team')
    ->label('scope', 'teams.read')
    ->label('sdk.namespace', 'teams')
    ->label('sdk.method', 'getTeam')
    ->label('sdk.description', 'Get team by its unique ID. All team members have read access for this resource.')
    ->param('teamId', '', function () {return new UID();}, 'Team unique ID.')
    ->action(
        function($teamId) use ($response, $projectDB)
        {
            $team = $projectDB->getDocument($teamId);

            if(empty($team->getUid()) || Database::SYSTEM_COLLECTION_TEAMS != $team->getCollection()) {
                throw new Exception('Team not found', 404);
            }

            $response->json($team->getArrayCopy([]));
        }
    );

$utopia->get('/v1/teams/:teamId/members')
    ->desc('Get Team Members')
    ->label('scope', 'teams.read')
    ->label('sdk.namespace', 'teams')
    ->label('sdk.method', 'getTeamMembers')
    ->label('sdk.description', 'Get team members by the team unique ID. All team members have read access for this list of resources.')
    ->param('teamId', '', function () {return new UID();}, 'Team unique ID.')
    ->action(
        function($teamId) use ($response, $projectDB)
        {
            $team = $projectDB->getDocument($teamId);

            if(empty($team->getUid()) || Database::SYSTEM_COLLECTION_TEAMS != $team->getCollection()) {
                throw new Exception('Team not found', 404);
            }

            $memberships = $projectDB->getCollection([
                'limit' => 50,
                'offset' => 0,
                'filters' => [
                    '$collection=' . Database::SYSTEM_COLLECTION_MEMBERSHIPS,
                    'teamId=' . $teamId
                ]
            ]);

            $users = [];

            foreach ($memberships as $member) {
                if(empty($member->getAttribute('userId', null))) {
                    continue;
                }

                $temp = $projectDB->getDocument($member->getAttribute('userId', null))->getArrayCopy(['$uid', 'email', 'name']);

                $temp['inviteId'] = $member->getUid();
                $temp['roles'] = $member->getAttribute('roles', []);
                $temp['confirm'] = $member->getAttribute('confirm', false);
                $temp['joined'] = $member->getAttribute('joined', 0);
                $users[] = $temp;
            }

            usort($users, function ($a, $b) {
                if($a['joined'] === 0 || $b['joined'] === 0) {
                    return $b['joined'] - $a['joined'];
                }
                return $a['joined'] - $b['joined'];
            });

            $response->json($users);
        }
    );

$utopia->post('/v1/teams')
    ->desc('Create Team')
    ->label('scope', 'teams.write')
    ->label('sdk.namespace', 'teams')
    ->label('sdk.method', 'createTeam')
    ->label('sdk.description', 'Create a new team. The user who creates the team will automatically be assigned as the owner of the team. The team owner can invite new members, who will be able add new owners and update or delete the team from your project.')
    ->param('name', null, function () {return new Text(100);}, 'Team name.')
    ->param('roles', ['owner'], function () {return new ArrayList(new Text(128));}, 'User roles array. Use this param to set the roles in the team for the user who created the team. The default role is **owner**, a role can be any string.', true)
    ->action(
        function($name, $roles) use ($response, $projectDB, $user, $mode)
        {
            Authorization::disable();

            $team = $projectDB->createDocument([
                '$collection' => Database::SYSTEM_COLLECTION_TEAMS,
                '$permissions'      => [
                    'read'      => ['team:{self}'],
                    'write'     => ['team:{self}/owner'],
                ],
                'name' => $name,
                'sum' => ($mode !== APP_MODE_ADMIN) ? 1 : 0,
                'dateCreated' => time(),
            ]);

            Authorization::enable();

            if(false === $team) {
                throw new Exception('Failed saving team to DB', 500);
            }

            if($mode !== APP_MODE_ADMIN) { // Don't add user on admin mode
                $membership = new Document([
                    '$collection' => Database::SYSTEM_COLLECTION_MEMBERSHIPS,
                    '$permissions'      => [
                        'read'      => ['user:' . $user->getUid(), 'team:' . $team->getUid()],
                        'write'     => ['user:' . $user->getUid(), 'team:' . $team->getUid() . '/owner'],
                    ],
                    'userId' => $user->getUid(),
                    'teamId' => $team->getUid(),
                    'roles' => $roles,
                    'invited' => time(),
                    'joined' => time(),
                    'confirm' => true,
                    'secret' => '',
                ]);

                // Attach user to team
                $user->setAttribute('memberships', $membership, Document::SET_TYPE_APPEND);

                $user = $projectDB->updateDocument($user->getArrayCopy());

                if(false === $user) {
                    throw new Exception('Failed saving user to DB', 500);
                }
            }

            $response
                ->setStatusCode(Response::STATUS_CODE_CREATED)
                ->json($team->getArrayCopy())
            ;
        }
    );

$utopia->put('/v1/teams/:teamId')
    ->desc('Update Team')
    ->label('scope', 'teams.write')
    ->label('sdk.namespace', 'teams')
    ->label('sdk.method', 'updateTeam')
    ->label('sdk.description', 'Update team by its unique ID. Only team owners have write access for this resource.')
    ->param('teamId', '', function () {return new UID();}, 'Team unique ID.')
    ->param('name', null, function () {return new Text(100);}, 'Team name.')
    ->action(
        function($teamId, $name) use ($response, $projectDB)
        {
            $team = $projectDB->getDocument($teamId);

            if(empty($team->getUid()) || Database::SYSTEM_COLLECTION_TEAMS != $team->getCollection()) {
                throw new Exception('Team not found', 404);
            }

            $team = $projectDB->updateDocument(array_merge($team->getArrayCopy(), [
                'name' => $name,
            ]));

            if(false === $team) {
                throw new Exception('Failed saving team to DB', 500);
            }

            $response->json($team->getArrayCopy());
        }
    );

$utopia->delete('/v1/teams/:teamId')
    ->desc('Delete Team')
    ->label('scope', 'teams.write')
    ->label('sdk.namespace', 'teams')
    ->label('sdk.method', 'deleteTeam')
    ->label('sdk.description', 'Delete team by its unique ID. Only team owners have write access for this resource.')
    ->param('teamId', '', function () {return new UID();}, 'Team unique ID.')
    ->action(
        function($teamId) use ($response, $projectDB)
        {
            $team = $projectDB->getDocument($teamId);

            if(empty($team->getUid()) || Database::SYSTEM_COLLECTION_TEAMS != $team->getCollection()) {
                throw new Exception('Team not found', 404);
            }

            $memberships = $projectDB->getCollection([
                'limit' => 2000, // TODO add members limit
                'offset' => 0,
                'filters' => [
                    '$collection=' . Database::SYSTEM_COLLECTION_MEMBERSHIPS,
                    'teamId=' . $teamId
                ]
            ]);

            foreach ($memberships as $member) {
                if(!$projectDB->deleteDocument($member)) {
                    throw new Exception('Failed to remove membership for team from DB', 500);
                }
            }

            if(!$projectDB->deleteDocument($teamId)) {
                throw new Exception('Failed to remove team from DB', 500);
            }

            $response->noContent();
        }
    );

// Memberships

$utopia->post('/v1/teams/:teamId/memberships')
    ->desc('Create Team Membership')
    ->label('scope', 'account')
    ->label('sdk.namespace', 'teams')
    ->label('sdk.method', 'createTeamMembership')
    ->label('sdk.description', "Use this endpoint to invite a new member to your team. An email with a link to join the team will be sent to the new member email address. If member doesn't exists in the project it will be automatically created.\n\nUse the redirect parameter to redirect the user from the invitation email back to your app. When the user is redirected, use the /teams/{teamId}/memberships/{inviteId}/status endpoint to finally join the user to the team.\n\nPlease notice that in order to avoid a [Redirect Attacks](https://github.com/OWASP/CheatSheetSeries/blob/master/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.md) the only valid redirect URL's are the once from domains you have set when added your platforms in the console interface.")
    ->param('teamId', '', function () {return new UID();}, 'Team unique ID.')
    ->param('email', '', function () {return new Email();}, 'New team member email address.')
    ->param('name', '', function () {return new Text(100);}, 'New team member name.', true)
    ->param('roles', [], function () {return new ArrayList(new Text(128));}, 'Invite roles array. Learn more about [roles and permissions](/docs/permissions).')
    ->param('redirect', '', function () use ($project) {return new Host($project->getAttribute('clients', []));}, 'Reset page to redirect user back to your app from the invitation email.')
    ->action(
        function($teamId, $email, $name, $roles, $redirect) use ($request, $response, $register, $project, $user, $audit, $projectDB)
        {
            $name = (empty($name)) ? $email : $name;
            $team = $projectDB->getDocument($teamId);

            if(empty($team->getUid()) || Database::SYSTEM_COLLECTION_TEAMS != $team->getCollection()) {
                throw new Exception('Team not found', 404);
            }

            $memberships = $projectDB->getCollection([
                'limit' => 50,
                'offset' => 0,
                'filters' => [
                    '$collection=' . Database::SYSTEM_COLLECTION_MEMBERSHIPS,
                    'teamId=' . $team->getUid()
                ]
            ]);

            $invitee = $projectDB->getCollection([ // Get user by email address
                'limit' => 1,
                'first' => true,
                'filters' => [
                    '$collection=' . Database::SYSTEM_COLLECTION_USERS,
                    'email=' . $email
                ]
            ]);

            if(empty($invitee)) { // Create new user if no user with same email found

                Authorization::disable();

                $invitee = $projectDB->createDocument([
                    '$collection' => Database::SYSTEM_COLLECTION_USERS,
                    '$permissions' => [
                        'read' => ['user:{self}', '*'],
                        'write' => ['user:{self}'],
                    ],
                    'email' => $email,
                    'status' => Auth::USER_STATUS_UNACTIVATED,
                    'password' => Auth::passwordHash(Auth::passwordGenerator()),
                    'password-update' => time(),
                    'registration' => time(),
                    'confirm' => false,
                    'reset' => false,
                    'name' => $name,
                    'tokens' => []
                ]);

                Authorization::enable();

                if(false === $invitee) {
                    throw new Exception('Failed saving user to DB', 500);
                }
            }

            $isOwner = false;

            foreach ($memberships as $member) {
                if($member->getAttribute('userId') ==  $invitee->getUid()) {
                    throw new Exception('User has already been invited or is already a member of this team', 400);
                }

                if($member->getAttribute('userId') == $user->getUid() && in_array('owner', $member->getAttribute('roles', []))) {
                    $isOwner = true;
                }
            }

            if(!$isOwner) {
                throw new Exception('User is not allowed to send invitations for this team', 401);
            }

            $secret = Auth::tokenGenerator();

            $membership = new Document([
                '$collection' => Database::SYSTEM_COLLECTION_MEMBERSHIPS,
                '$permissions'      => [
                    'read'      => ['*'],
                    'write'     => ['user:' . $invitee->getUid(), 'team:' . $team->getUid() . '/owner'],
                ],
                'userId' => $invitee->getUid(),
                'teamId' => $team->getUid(),
                'roles' => $roles,
                'invited' => time(),
                'joined' => 0,
                'confirm' => false,
                'secret' => Auth::hash($secret),
            ]);

            $membership = $projectDB->createDocument($membership->getArrayCopy());

            if(false === $membership) {
                throw new Exception('Failed saving membership to DB', 500);
            }

            $redirect = Template::parseURL($redirect);
            $redirect['query'] = Template::mergeQuery(((isset($redirect['query'])) ? $redirect['query'] : ''), ['inviteId' => $membership->getUid(), 'teamId' => $team->getUid(), 'userId' => $invitee->getUid(), 'secret' => $secret]);
            $redirect = Template::unParseURL($redirect);

            $body = new Template(__DIR__ . '/../config/locale/templates/' . Locale::getText('auth.emails.invitation.body'));
            $body
                ->setParam('{{direction}}', Locale::getText('settings.direction'))
                ->setParam('{{project}}', $project->getAttribute('name', ['[APP-NAME]']))
                ->setParam('{{team}}', $team->getAttribute('name', '[TEAM-NAME]'))
                ->setParam('{{owner}}', $user->getAttribute('name', ''))
                ->setParam('{{redirect}}', $redirect)
            ;

            $mail = $register->get('smtp'); /* @var $mail \MailgunLite\MailgunLite */

            $mail->addAddress($email, $name);

            $mail->Subject = sprintf(Locale::getText('auth.emails.invitation.title'), $team->getAttribute('name', '[TEAM-NAME]'), $project->getAttribute('name', ['[APP-NAME]']));
            $mail->Body    = $body->render();
            $mail->AltBody = strip_tags($body->render());

            try {
                $mail->send();
            }
            catch(Exception $error) {
                throw new Exception('Problem sending mail: ' . $mail->getError(), 500);
            }

            $audit
                ->setParam('userId', $invitee->getUid())
                ->setParam('event', 'auth.invite')
            ;

            $response
                //->setStatusCode(Response::STATUS_CODE_CREATED) TODO change response of this endpoint
                ->noContent();
        }
    );

$utopia->post('/v1/teams/:teamId/memberships/:inviteId/resend')
    ->desc('Create Team Membership (Resend Invitation Email)')
    ->label('scope', 'account')
    ->label('sdk.namespace', 'teams')
    ->label('sdk.method', 'createTeamMembershipResend')
    ->label('sdk.description', 'Use this endpoint to resend your invitation email for a user to join a team.')
    ->param('teamId', '', function () {return new UID();}, 'Team unique ID.')
    ->param('inviteId', '', function () {return new UID();}, 'Invite unique ID.')
    ->param('redirect', '', function () use ($project) {return new Host($project->getAttribute('clients', []));}, 'Reset page to redirect user back to your app from the invitation email.')
    ->action(
        function($teamId, $inviteId, $redirect) use ($response, $register, $project, $user, $audit, $projectDB)
        {
            $membership = $projectDB->getDocument($inviteId);

            if(empty($membership->getUid()) || Database::SYSTEM_COLLECTION_MEMBERSHIPS != $membership->getCollection()) {
                throw new Exception('Membership not found', 404);
            }

            $team = $projectDB->getDocument($membership->getAttribute('teamId'));

            if(empty($team->getUid()) || Database::SYSTEM_COLLECTION_TEAMS != $team->getCollection()) {
                throw new Exception('Team not found', 404);
            }

            if($team->getUid() !== $teamId) {
                throw new Exception('Team IDs don\'t match', 404);
            }

            $invitee = $projectDB->getDocument($membership->getAttribute('userId'));

            if(empty($invitee->getUid()) || Database::SYSTEM_COLLECTION_USERS != $invitee->getCollection()) {
                throw new Exception('User not found', 404);
            }

            $secret = Auth::tokenGenerator();

            $membership = $projectDB->updateDocument(array_merge($membership->getArrayCopy(), ['secret' => Auth::hash($secret)]));

            if(false === $membership) {
                throw new Exception('Failed updating membership to DB', 500);
            }

            $redirect = Template::parseURL($redirect);
            $redirect['query'] = Template::mergeQuery(((isset($redirect['query'])) ? $redirect['query'] : ''), ['inviteId' => $membership->getUid(), 'userId' => $membership->getAttribute('userId'), 'secret' => $secret]);
            $redirect = Template::unParseURL($redirect);

            $body = new Template(__DIR__ . '/../config/locale/templates/' . Locale::getText('auth.emails.invitation.body'));
            $body
                ->setParam('{{direction}}', Locale::getText('settings.direction'))
                ->setParam('{{project}}', $project->getAttribute('name', ['[APP-NAME]']))
                ->setParam('{{team}}', $team->getAttribute('name', '[TEAM-NAME]'))
                ->setParam('{{owner}}', $user->getAttribute('name', ''))
                ->setParam('{{redirect}}', $redirect)
            ;

            $mail = $register->get('smtp'); /* @var $mail \MailgunLite\MailgunLite */

            $mail->addAddress($invitee->getAttribute('email'), $invitee->getAttribute('name'));

            $mail->Subject = sprintf(Locale::getText('auth.emails.invitation.title'), $team->getAttribute('name', '[TEAM-NAME]'), $project->getAttribute('name', ['[APP-NAME]']));
            $mail->Body    = $body->render();
            $mail->AltBody = strip_tags($body->render());

            try {
                $mail->send();
            }
            catch(Exception $error) {
                throw new Exception('Problem sending mail: ' . $mail->getError(), 500);
            }
            
            $audit
                ->setParam('userId', $user->getUid())
                ->setParam('event', 'auth.invite.resend')
            ;

            $response
            //    ->setStatusCode(Response::STATUS_CODE_CREATED) TODO change response of this endpoint
                ->noContent()
            ;
        }
    );

$utopia->patch('/v1/teams/:teamId/memberships/:inviteId/status')
    ->desc('Update Team Membership Status')
    ->label('scope', 'auth')
    ->label('sdk.namespace', 'teams')
    ->label('sdk.method', 'updateTeamMembershipStatus')
    ->label('sdk.description', "Use this endpoint to let user accept an invitation to join a team after he is being redirect back to your app from the invitation email. Use the success and failure URL's to redirect users back to your application after the request completes.\n\nPlease notice that in order to avoid a [Redirect Attacks](https://github.com/OWASP/CheatSheetSeries/blob/master/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.md) the only valid redirect URL's are the once from domains you have set when added your platforms in the console interface.\n\nWhen not using the success or failure redirect arguments this endpoint will result with a 200 status code on success and with 401 status error on failure. This behavior was applied to help the web clients deal with browsers who don't allow to set 3rd party HTTP cookies needed for saving the account session token.")
    ->label('sdk.cookies', true)
    ->param('teamId', '', function () {return new UID();}, 'Team unique ID.')
    ->param('inviteId', '', function () {return new UID();}, 'Invite unique ID')
    ->param('userId', '', function () {return new UID();}, 'User unique ID')
    ->param('secret', '', function () {return new Text(256);}, 'Secret Key')
    ->param('success', null, function () use ($project) {return new Host($project->getAttribute('clients', []));}, 'Redirect when registration succeed', true)
    ->param('failure', null, function () use ($project) {return new Host($project->getAttribute('clients', []));}, 'Redirect when registration failed', true)
    ->action(
        function($teamId, $inviteId, $userId, $secret, $success, $failure) use ($response, $request, $user, $audit, $projectDB)
        {
            $invite = $projectDB->getDocument($inviteId);

            if(empty($invite->getUid()) || Database::SYSTEM_COLLECTION_MEMBERSHIPS != $invite->getCollection()) {
                if($failure) {
                    $response->redirect($failure);
                    return;
                }

                throw new Exception('Invite not found', 404);
            }

            if($invite->getAttribute('teamId')->getUid() !== $teamId) {
                throw new Exception('Team IDs don\'t match', 404);
            }

            $team = $projectDB->getDocument($teamId);

            if(empty($team->getUid()) || Database::SYSTEM_COLLECTION_TEAMS != $team->getCollection()) {
                throw new Exception('Team not found', 404);
            }

            if(Auth::hash($secret) !== $invite->getAttribute('secret')) {
                if($failure) {
                    $response->redirect($failure);
                    return;
                }

                throw new Exception('Secret key not valid', 401);
            }

            if($userId != $invite->getAttribute('userId')) {
                if($failure) {
                    $response->redirect($failure);
                    return;
                }

                throw new Exception('Invite not belong to current user (' . $user->getAttribute('email') . ')', 401);
            }

            if(empty($user->getUid())) {
                $user = $projectDB->getCollection([ // Get user
                    'limit' => 1,
                    'first' => true,
                    'filters' => [
                        '$collection=' . Database::SYSTEM_COLLECTION_USERS,
                        '$uid=' . $userId
                    ]
                ]);
            }

            if($invite->getAttribute('userId') !== $user->getUid()) {
                if($failure) {
                    $response->redirect($failure);
                    return;
                }

                throw new Exception('Invite not belong to current user (' . $user->getAttribute('email') . ')', 401);
            }

            $invite // Attach user to team
                ->setAttribute('joined', time())
                ->setAttribute('confirm', true)
            ;

            $user
                ->setAttribute('confirm', true)
                ->setAttribute('memberships', $invite, Document::SET_TYPE_APPEND);

            // Log user in
            $expiry = time() + Auth::TOKEN_EXPIRATION_LOGIN_LONG;
            $secret = Auth::tokenGenerator();

            $user->setAttribute('tokens', new Document([
                '$collection' => Database::SYSTEM_COLLECTION_TOKENS,
                '$permissions' => ['read' => ['user:' . $user->getUid()], 'write' => ['user:' . $user->getUid()]],
                'type' => Auth::TOKEN_TYPE_LOGIN,
                'secret' => Auth::hash($secret), // On way hash encryption to protect DB leak
                'expire' => $expiry,
                'userAgent' => $request->getServer('HTTP_USER_AGENT', 'UNKNOWN'),
                'ip' => $request->getIP(),
            ]),Document::SET_TYPE_APPEND);

            Authorization::setRole('user:' . $userId);

            $user = $projectDB->updateDocument($user->getArrayCopy());

            if(false === $user) {
                throw new Exception('Failed saving user to DB', 500);
            }

            $team = $projectDB->updateDocument(array_merge($team->getArrayCopy(), [
                'sum' => $team->getAttribute('sum', 0) + 1,
            ]));

            if(false === $team) {
                throw new Exception('Failed saving team to DB', 500);
            }

            $audit
                ->setParam('userId', $user->getUid())
                ->setParam('event', 'auth.join')
            ;

            $response->addCookie(Auth::$cookieName, Auth::encodeSession($user->getUid(), $secret), $expiry, '/', COOKIE_DOMAIN, ('https' == APP_PROTOCOL), true);

            if($success) {
                $response->redirect($success);
            }

            $response->json(array('result' => 'success'));
        }
    );

$utopia->delete('/v1/teams/:teamId/memberships/:inviteId')
    ->desc('Delete Team Membership')
    ->label('scope', 'account')
    ->label('sdk.namespace', 'teams')
    ->label('sdk.method', 'deleteTeamMembership')
    ->label('sdk.description', "This endpoint allows a user to leave a team or for a team owner to delete the membership of any other team member.")
    ->param('teamId', '', function () {return new UID();}, 'Team unique ID.')
    ->param('inviteId', '', function () {return new UID();}, 'Invite unique ID')
    ->action(
        function($teamId, $inviteId) use ($response, $projectDB, $audit)
        {
            $invite = $projectDB->getDocument($inviteId);

            if(empty($invite->getUid()) || Database::SYSTEM_COLLECTION_MEMBERSHIPS != $invite->getCollection()) {
                throw new Exception('Invite not found', 404);
            }

            if($invite->getAttribute('teamId') !== $teamId) {
                throw new Exception('Team IDs don\'t match', 404);
            }

            $team = $projectDB->getDocument($teamId);

            if(empty($team->getUid()) || Database::SYSTEM_COLLECTION_TEAMS != $team->getCollection()) {
                throw new Exception('Team not found', 404);
            }

            if(!$projectDB->deleteDocument($invite->getUid())) {
                throw new Exception('Failed to remove membership from DB', 500);
            }

            $team = $projectDB->updateDocument(array_merge($team->getArrayCopy(), [
                'sum' => $team->getAttribute('sum', 0) - 1,
            ]));

            if(false === $team) {
                throw new Exception('Failed saving team to DB', 500);
            }

            $audit
                ->setParam('userId', $invite->getAttribute('userId'))
                ->setParam('event', 'auth.leave')
            ;

            $response->noContent();
        }
    );