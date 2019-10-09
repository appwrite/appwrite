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
     * /docs/references/teams/list-teams.md
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
     * /docs/references/teams/create-team.md
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
     * /docs/references/teams/get-team.md
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
     * /docs/references/teams/update-team.md
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
     * /docs/references/teams/delete-team.md
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
     * /docs/references/teams/get-team-members.md
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
     * /docs/references/teams/create-team-membership.md
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
     * /docs/references/teams/delete-team-membership.md
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
     * Create Team Membership (Resend)
     *
     * /docs/references/teams/create-team-membership-resend.md
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
     * /docs/references/teams/update-team-membership-status.md
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