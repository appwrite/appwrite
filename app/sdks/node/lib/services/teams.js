const Service = require('../service.js');

class Teams extends Service {

    /**
     * List Teams
     *
     * /docs/references/teams/list-teams.md
     *
     * @param string search
     * @param number limit
     * @param number offset
     * @param string orderType
     * @throws Exception
     * @return {}
     */
    async listTeams(search = '', limit = 25, offset = 0, orderType = 'ASC') {
        let path = '/teams';
        
        return await this.client.call('get', path, {'content-type': 'application/json'},
            {
                'search': search,
                'limit': limit,
                'offset': offset,
                'orderType': orderType
            });
    }

    /**
     * Create Team
     *
     * /docs/references/teams/create-team.md
     *
     * @param string name
     * @param array roles
     * @throws Exception
     * @return {}
     */
    async createTeam(name, roles = ["owner"]) {
        let path = '/teams';
        
        return await this.client.call('post', path, {'content-type': 'application/json'},
            {
                'name': name,
                'roles': roles
            });
    }

    /**
     * Get Team
     *
     * /docs/references/teams/get-team.md
     *
     * @param string teamId
     * @throws Exception
     * @return {}
     */
    async getTeam(teamId) {
        let path = '/teams/{teamId}'.replace(new RegExp('{teamId}', 'g'), teamId);
        
        return await this.client.call('get', path, {'content-type': 'application/json'},
            {
            });
    }

    /**
     * Update Team
     *
     * /docs/references/teams/update-team.md
     *
     * @param string teamId
     * @param string name
     * @throws Exception
     * @return {}
     */
    async updateTeam(teamId, name) {
        let path = '/teams/{teamId}'.replace(new RegExp('{teamId}', 'g'), teamId);
        
        return await this.client.call('put', path, {'content-type': 'application/json'},
            {
                'name': name
            });
    }

    /**
     * Delete Team
     *
     * /docs/references/teams/delete-team.md
     *
     * @param string teamId
     * @throws Exception
     * @return {}
     */
    async deleteTeam(teamId) {
        let path = '/teams/{teamId}'.replace(new RegExp('{teamId}', 'g'), teamId);
        
        return await this.client.call('delete', path, {'content-type': 'application/json'},
            {
            });
    }

    /**
     * Get Team Members
     *
     * /docs/references/teams/get-team-members.md
     *
     * @param string teamId
     * @throws Exception
     * @return {}
     */
    async getTeamMembers(teamId) {
        let path = '/teams/{teamId}/members'.replace(new RegExp('{teamId}', 'g'), teamId);
        
        return await this.client.call('get', path, {'content-type': 'application/json'},
            {
            });
    }

    /**
     * Create Team Membership
     *
     * /docs/references/teams/create-team-membership.md
     *
     * @param string teamId
     * @param string email
     * @param array roles
     * @param string redirect
     * @param string name
     * @throws Exception
     * @return {}
     */
    async createTeamMembership(teamId, email, roles, redirect, name = '') {
        let path = '/teams/{teamId}/memberships'.replace(new RegExp('{teamId}', 'g'), teamId);
        
        return await this.client.call('post', path, {'content-type': 'application/json'},
            {
                'email': email,
                'name': name,
                'roles': roles,
                'redirect': redirect
            });
    }

    /**
     * Delete Team Membership
     *
     * /docs/references/teams/delete-team-membership.md
     *
     * @param string teamId
     * @param string inviteId
     * @throws Exception
     * @return {}
     */
    async deleteTeamMembership(teamId, inviteId) {
        let path = '/teams/{teamId}/memberships/{inviteId}'.replace(new RegExp('{teamId}', 'g'), teamId).replace(new RegExp('{inviteId}', 'g'), inviteId);
        
        return await this.client.call('delete', path, {'content-type': 'application/json'},
            {
            });
    }

    /**
     * Create Team Membership (Resend)
     *
     * /docs/references/teams/create-team-membership-resend.md
     *
     * @param string teamId
     * @param string inviteId
     * @param string redirect
     * @throws Exception
     * @return {}
     */
    async createTeamMembershipResend(teamId, inviteId, redirect) {
        let path = '/teams/{teamId}/memberships/{inviteId}/resend'.replace(new RegExp('{teamId}', 'g'), teamId).replace(new RegExp('{inviteId}', 'g'), inviteId);
        
        return await this.client.call('post', path, {'content-type': 'application/json'},
            {
                'redirect': redirect
            });
    }

    /**
     * Update Team Membership Status
     *
     * /docs/references/teams/update-team-membership-status.md
     *
     * @param string teamId
     * @param string inviteId
     * @param string userId
     * @param string secret
     * @param string success
     * @param string failure
     * @throws Exception
     * @return {}
     */
    async updateTeamMembershipStatus(teamId, inviteId, userId, secret, success = '', failure = '') {
        let path = '/teams/{teamId}/memberships/{inviteId}/status'.replace(new RegExp('{teamId}', 'g'), teamId).replace(new RegExp('{inviteId}', 'g'), inviteId);
        
        return await this.client.call('patch', path, {'content-type': 'application/json'},
            {
                'userId': userId,
                'secret': secret,
                'success': success,
                'failure': failure
            });
    }
}

module.exports = Teams;