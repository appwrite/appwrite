const Service = require('../service.js');

class Teams extends Service {

    /**
     * List Teams
     *
     * Get a list of all the current user teams. You can use the query params to
     * filter your results. On admin mode, this endpoint will return a list of all
     * of the project teams. [Learn more about different API modes](/docs/modes).
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
     * Create a new team. The user who creates the team will automatically be
     * assigned as the owner of the team. The team owner can invite new members,
     * who will be able add new owners and update or delete the team from your
     * project.
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
     * Get team by its unique ID. All team members have read access for this
     * resource.
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
     * Update team by its unique ID. Only team owners have write access for this
     * resource.
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
     * Delete team by its unique ID. Only team owners have write access for this
     * resource.
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
     * Get team members by the team unique ID. All team members have read access
     * for this list of resources.
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
     * This endpoint allows a user to leave a team or for a team owner to delete
     * the membership of any other team member.
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
     * Use this endpoint to resend your invitation email for a user to join a
     * team.
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