<?php

namespace Appwrite\Services;

use Exception;
use Appwrite\Client;
use Appwrite\Service;

class Teams extends Service
{
    /**
     * List Teams
     *
     * Get a list of all the current user teams. You can use the query params to
     * filter your results. On admin mode, this endpoint will return a list of all
     * of the project teams. [Learn more about different API modes](/docs/admin).
     *
     * @param string  $search
     * @param int  $limit
     * @param int  $offset
     * @param string  $orderType
     * @throws Exception
     * @return array
     */
    public function listTeams(string $search = '', int $limit = 25, int $offset = 0, string $orderType = 'ASC'):array
    {
        $path   = str_replace([], [], '/teams');
        $params = [];

        $params['search'] = $search;
        $params['limit'] = $limit;
        $params['offset'] = $offset;
        $params['orderType'] = $orderType;

        return $this->client->call(Client::METHOD_GET, $path, [
            'content-type' => 'application/json',
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
     * @param string  $name
     * @param array  $roles
     * @throws Exception
     * @return array
     */
    public function createTeam(string $name, array $roles = ["owner"]):array
    {
        $path   = str_replace([], [], '/teams');
        $params = [];

        $params['name'] = $name;
        $params['roles'] = $roles;

        return $this->client->call(Client::METHOD_POST, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Get Team
     *
     * Get team by its unique ID. All team members have read access for this
     * resource.
     *
     * @param string  $teamId
     * @throws Exception
     * @return array
     */
    public function getTeam(string $teamId):array
    {
        $path   = str_replace(['{teamId}'], [$teamId], '/teams/{teamId}');
        $params = [];


        return $this->client->call(Client::METHOD_GET, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Update Team
     *
     * Update team by its unique ID. Only team owners have write access for this
     * resource.
     *
     * @param string  $teamId
     * @param string  $name
     * @throws Exception
     * @return array
     */
    public function updateTeam(string $teamId, string $name):array
    {
        $path   = str_replace(['{teamId}'], [$teamId], '/teams/{teamId}');
        $params = [];

        $params['name'] = $name;

        return $this->client->call(Client::METHOD_PUT, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Delete Team
     *
     * Delete team by its unique ID. Only team owners have write access for this
     * resource.
     *
     * @param string  $teamId
     * @throws Exception
     * @return array
     */
    public function deleteTeam(string $teamId):array
    {
        $path   = str_replace(['{teamId}'], [$teamId], '/teams/{teamId}');
        $params = [];


        return $this->client->call(Client::METHOD_DELETE, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Get Team Memberships
     *
     * Get team members by the team unique ID. All team members have read access
     * for this list of resources.
     *
     * @param string  $teamId
     * @throws Exception
     * @return array
     */
    public function getTeamMemberships(string $teamId):array
    {
        $path   = str_replace(['{teamId}'], [$teamId], '/teams/{teamId}/memberships');
        $params = [];


        return $this->client->call(Client::METHOD_GET, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Create Team Membership
     *
     * Use this endpoint to invite a new member to your team. An email with a link
     * to join the team will be sent to the new member email address. If member
     * doesn't exists in the project it will be automatically created.
     * 
     * Use the 'url' parameter to redirect the user from the invitation email back
     * to your app. When the user is redirected, use the [Update Team Membership
     * Status](/docs/teams#updateTeamMembershipStatus) endpoint to finally join
     * the user to the team.
     * 
     * Please note that in order to avoid a [Redirect
     * Attacks](https://github.com/OWASP/CheatSheetSeries/blob/master/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.md)
     * the only valid redirect URL's are the once from domains you have set when
     * added your platforms in the console interface.
     *
     * @param string  $teamId
     * @param string  $email
     * @param array  $roles
     * @param string  $url
     * @param string  $name
     * @throws Exception
     * @return array
     */
    public function createTeamMembership(string $teamId, string $email, array $roles, string $url, string $name = ''):array
    {
        $path   = str_replace(['{teamId}'], [$teamId], '/teams/{teamId}/memberships');
        $params = [];

        $params['email'] = $email;
        $params['name'] = $name;
        $params['roles'] = $roles;
        $params['url'] = $url;

        return $this->client->call(Client::METHOD_POST, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Delete Team Membership
     *
     * This endpoint allows a user to leave a team or for a team owner to delete
     * the membership of any other team member. You can also use this endpoint to
     * delete a user membership even if he didn't accept it.
     *
     * @param string  $teamId
     * @param string  $inviteId
     * @throws Exception
     * @return array
     */
    public function deleteTeamMembership(string $teamId, string $inviteId):array
    {
        $path   = str_replace(['{teamId}', '{inviteId}'], [$teamId, $inviteId], '/teams/{teamId}/memberships/{inviteId}');
        $params = [];


        return $this->client->call(Client::METHOD_DELETE, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

}