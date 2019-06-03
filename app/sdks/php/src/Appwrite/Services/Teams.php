<?php

namespace Appwrite\Services;

use Exception;
use Appwrite\Client;
use Appwrite\Service;

class Teams extends Service
{
    /**
     * Update Team
     *
     * Update team by its unique ID. Only team owners have write access for this
     * resource.
     *
     * @param string $collectionId
     * @param string $name
     * @param array $read
     * @param array $write
     * @param array $rules
     * @throws Exception
     * @return array
     */
    public function updateTeam($collectionId, $name, $read = [], $write = [], $rules = [])
    {
        $path   = str_replace(['{collectionId}'], [$collectionId], '/database/{collectionId}');
        $params = [];

        $params['name'] = $name;
        $params['read'] = $read;
        $params['write'] = $write;
        $params['rules'] = $rules;

        return $this->client->call(Client::METHOD_PUT, $path, [
        ], $params);
    }

    /**
     * List Teams
     *
     * Get a list of all the current user teams. You can use the query params to
     * filter your results. On admin mode, this endpoint will return a list of all
     * of the project teams. [Learn more about different API modes](/docs/modes).
     *
     * @param string $search
     * @param integer $limit
     * @param integer $offset
     * @param string $orderType
     * @throws Exception
     * @return array
     */
    public function listTeams($search = '', $limit = 25, $offset = 0, $orderType = 'ASC')
    {
        $path   = str_replace([], [], '/teams');
        $params = [];

        $params['search'] = $search;
        $params['limit'] = $limit;
        $params['offset'] = $offset;
        $params['orderType'] = $orderType;

        return $this->client->call(Client::METHOD_GET, $path, [
        ], $params);
    }

    /**
     * Create Team
     *
     * Create a new team. The user who creates the team will automatically be
     * assigned as the owner of the team. The team owner can invite new members,
     * who will be able add new owners and update or delete the team from your
     * project.
     *
     * @param string $name
     * @param array $roles
     * @throws Exception
     * @return array
     */
    public function createTeam($name, $roles = ["owner"])
    {
        $path   = str_replace([], [], '/teams');
        $params = [];

        $params['name'] = $name;
        $params['roles'] = $roles;

        return $this->client->call(Client::METHOD_POST, $path, [
        ], $params);
    }

    /**
     * Get Team
     *
     * Get team by its unique ID. All team members have read access for this
     * resource.
     *
     * @param string $teamId
     * @throws Exception
     * @return array
     */
    public function getTeam($teamId)
    {
        $path   = str_replace(['{teamId}'], [$teamId], '/teams/{teamId}');
        $params = [];


        return $this->client->call(Client::METHOD_GET, $path, [
        ], $params);
    }

    /**
     * Update Team
     *
     * Update team by its unique ID. Only team owners have write access for this
     * resource.
     *
     * @param string $teamId
     * @param string $name
     * @throws Exception
     * @return array
     */
    public function updateTeam($teamId, $name)
    {
        $path   = str_replace(['{teamId}'], [$teamId], '/teams/{teamId}');
        $params = [];

        $params['name'] = $name;

        return $this->client->call(Client::METHOD_PUT, $path, [
        ], $params);
    }

    /**
     * Delete Team
     *
     * Delete team by its unique ID. Only team owners have write access for this
     * resource.
     *
     * @param string $teamId
     * @throws Exception
     * @return array
     */
    public function deleteTeam($teamId)
    {
        $path   = str_replace(['{teamId}'], [$teamId], '/teams/{teamId}');
        $params = [];


        return $this->client->call(Client::METHOD_DELETE, $path, [
        ], $params);
    }

    /**
     * Get Team Members
     *
     * Get team members by the team unique ID. All team members have read access
     * for this list of resources.
     *
     * @param string $teamId
     * @throws Exception
     * @return array
     */
    public function getTeamMembers($teamId)
    {
        $path   = str_replace(['{teamId}'], [$teamId], '/teams/{teamId}/members');
        $params = [];


        return $this->client->call(Client::METHOD_GET, $path, [
        ], $params);
    }

    /**
     * Create Team Membership
     *
     * Use this endpoint to invite a new member to your team. An email with a link
     * to join the team will be sent to the new member email address. If member
     * doesn't exists in the project it will be automatically created.
     * 
     * Use the redirect parameter to redirect the user from the invitation email
     * back to your app. When the user is redirected, use the
     * /teams/{teamId}/memberships/{inviteId}/status endpoint to finally join the
     * user to the team.
     * 
     * Please notice that in order to avoid a [Redirect
     * Attacks](https://github.com/OWASP/CheatSheetSeries/blob/master/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.md)
     * the only valid redirect URL's are the once from domains you have set when
     * added your platforms in the console interface.
     *
     * @param string $teamId
     * @param string $email
     * @param array $roles
     * @param string $redirect
     * @param string $name
     * @throws Exception
     * @return array
     */
    public function createTeamMembership($teamId, $email, $roles, $redirect, $name = '')
    {
        $path   = str_replace(['{teamId}'], [$teamId], '/teams/{teamId}/memberships');
        $params = [];

        $params['email'] = $email;
        $params['name'] = $name;
        $params['roles'] = $roles;
        $params['redirect'] = $redirect;

        return $this->client->call(Client::METHOD_POST, $path, [
        ], $params);
    }

    /**
     * Delete Team Membership
     *
     * This endpoint allows a user to leave a team or for a team owner to delete
     * the membership of any other team member.
     *
     * @param string $teamId
     * @param string $inviteId
     * @throws Exception
     * @return array
     */
    public function deleteTeamMembership($teamId, $inviteId)
    {
        $path   = str_replace(['{teamId}', '{inviteId}'], [$teamId, $inviteId], '/teams/{teamId}/memberships/{inviteId}');
        $params = [];


        return $this->client->call(Client::METHOD_DELETE, $path, [
        ], $params);
    }

    /**
     * Create Team Membership (Resend Invitation Email)
     *
     * Use this endpoint to resend your invitation email for a user to join a
     * team.
     *
     * @param string $teamId
     * @param string $inviteId
     * @param string $redirect
     * @throws Exception
     * @return array
     */
    public function createTeamMembershipResend($teamId, $inviteId, $redirect)
    {
        $path   = str_replace(['{teamId}', '{inviteId}'], [$teamId, $inviteId], '/teams/{teamId}/memberships/{inviteId}/resend');
        $params = [];

        $params['redirect'] = $redirect;

        return $this->client->call(Client::METHOD_POST, $path, [
        ], $params);
    }

    /**
     * Update Team Membership Status
     *
     * Use this endpoint to let user accept an invitation to join a team after he
     * is being redirect back to your app from the invitation email. Use the
     * success and failure URL's to redirect users back to your application after
     * the request completes.
     * 
     * Please notice that in order to avoid a [Redirect
     * Attacks](https://github.com/OWASP/CheatSheetSeries/blob/master/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.md)
     * the only valid redirect URL's are the once from domains you have set when
     * added your platforms in the console interface.
     * 
     * When not using the success or failure redirect arguments this endpoint will
     * result with a 200 status code on success and with 401 status error on
     * failure. This behavior was applied to help the web clients deal with
     * browsers who don't allow to set 3rd party HTTP cookies needed for saving
     * the account session token.
     *
     * @param string $teamId
     * @param string $inviteId
     * @param string $userId
     * @param string $secret
     * @param string $success
     * @param string $failure
     * @throws Exception
     * @return array
     */
    public function updateTeamMembershipStatus($teamId, $inviteId, $userId, $secret, $success = '', $failure = '')
    {
        $path   = str_replace(['{teamId}', '{inviteId}'], [$teamId, $inviteId], '/teams/{teamId}/memberships/{inviteId}/status');
        $params = [];

        $params['userId'] = $userId;
        $params['secret'] = $secret;
        $params['success'] = $success;
        $params['failure'] = $failure;

        return $this->client->call(Client::METHOD_PATCH, $path, [
        ], $params);
    }

}