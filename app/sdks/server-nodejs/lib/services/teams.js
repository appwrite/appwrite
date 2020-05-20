const Service = require('../service.js');

class Teams extends Service {

    /**
     * List Teams
     *
     * Get a list of all the current user teams. You can use the query params to
     * filter your results. On admin mode, this endpoint will return a list of all
     * of the project teams. [Learn more about different API modes](/docs/admin).
     *
     * @param string search
     * @param number limit
     * @param number offset
     * @param string orderType
     * @throws Exception
     * @return {}
     */
    async list(search = '', limit = 25, offset = 0, orderType = 'ASC') {
        let path = '/teams';
        
        return await this.client.call('get', path, {
                    'content-type': 'application/json',
               },
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
     * @param string[] roles
     * @throws Exception
     * @return {}
     */
    async create(name, roles = ["owner"]) {
        let path = '/teams';
        
        return await this.client.call('post', path, {
                    'content-type': 'application/json',
               },
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
    async get(teamId) {
        let path = '/teams/{teamId}'.replace(new RegExp('{teamId}', 'g'), teamId);
        
        return await this.client.call('get', path, {
                    'content-type': 'application/json',
               },
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
    async update(teamId, name) {
        let path = '/teams/{teamId}'.replace(new RegExp('{teamId}', 'g'), teamId);
        
        return await this.client.call('put', path, {
                    'content-type': 'application/json',
               },
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
    async delete(teamId) {
        let path = '/teams/{teamId}'.replace(new RegExp('{teamId}', 'g'), teamId);
        
        return await this.client.call('delete', path, {
                    'content-type': 'application/json',
               },
               {
            });
    }

    /**
     * Get Team Memberships
     *
     * Get team members by the team unique ID. All team members have read access
     * for this list of resources.
     *
     * @param string teamId
     * @throws Exception
     * @return {}
     */
    async getMemberships(teamId) {
        let path = '/teams/{teamId}/memberships'.replace(new RegExp('{teamId}', 'g'), teamId);
        
        return await this.client.call('get', path, {
                    'content-type': 'application/json',
               },
               {
            });
    }

    /**
     * Create Team Membership
     *
     * Use this endpoint to invite a new member to join your team. An email with a
     * link to join the team will be sent to the new member email address if the
     * member doesn't exist in the project it will be created automatically.
     * 
     * Use the 'URL' parameter to redirect the user from the invitation email back
     * to your app. When the user is redirected, use the [Update Team Membership
     * Status](/docs/teams#updateMembershipStatus) endpoint to allow the user to
     * accept the invitation to the team.
     * 
     * Please note that in order to avoid a [Redirect
     * Attacks](https://github.com/OWASP/CheatSheetSeries/blob/master/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.md)
     * the only valid redirect URL's are the once from domains you have set when
     * added your platforms in the console interface.
     *
     * @param string teamId
     * @param string email
     * @param string[] roles
     * @param string url
     * @param string name
     * @throws Exception
     * @return {}
     */
    async createMembership(teamId, email, roles, url, name = '') {
        let path = '/teams/{teamId}/memberships'.replace(new RegExp('{teamId}', 'g'), teamId);
        
        return await this.client.call('post', path, {
                    'content-type': 'application/json',
               },
               {
                'email': email,
                'name': name,
                'roles': roles,
                'url': url
            });
    }

    /**
     * Delete Team Membership
     *
     * This endpoint allows a user to leave a team or for a team owner to delete
     * the membership of any other team member. You can also use this endpoint to
     * delete a user membership even if he didn't accept it.
     *
     * @param string teamId
     * @param string inviteId
     * @throws Exception
     * @return {}
     */
    async deleteMembership(teamId, inviteId) {
        let path = '/teams/{teamId}/memberships/{inviteId}'.replace(new RegExp('{teamId}', 'g'), teamId).replace(new RegExp('{inviteId}', 'g'), inviteId);
        
        return await this.client.call('delete', path, {
                    'content-type': 'application/json',
               },
               {
            });
    }
}

module.exports = Teams;