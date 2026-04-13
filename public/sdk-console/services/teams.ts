import { Service } from '../service';
import { AppwriteException, Client } from '../client';
import type { Models } from '../models';
import type { UploadProgress, Payload } from '../client';

export class Teams extends Service {

     constructor(client: Client)
     {
        super(client);
     }

        /**
         * List Teams
         *
         * Get a list of all the teams in which the current user is a member. You can
         * use the parameters to filter your results.
         * 
         * In admin mode, this endpoint returns a list of all the teams in the current
         * project. [Learn more about different API modes](/docs/admin).
         *
         * @param {string[]} queries
         * @param {string} search
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        async list(queries?: string[], search?: string): Promise<Models.TeamList> {
            let path = '/teams';
            let payload: Payload = {};

            if (typeof queries !== 'undefined') {
                payload['queries'] = queries;
            }

            if (typeof search !== 'undefined') {
                payload['search'] = search;
            }

            const uri = new URL(this.client.config.endpoint + path);
            return await this.client.call('get', uri, {
                'content-type': 'application/json',
            }, payload);
        }

        /**
         * Create Team
         *
         * Create a new team. The user who creates the team will automatically be
         * assigned as the owner of the team. Only the users with the owner role can
         * invite new members, add new owners and delete or update the team.
         *
         * @param {string} teamId
         * @param {string} name
         * @param {string[]} roles
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        async create(teamId: string, name: string, roles?: string[]): Promise<Models.Team> {
            if (typeof teamId === 'undefined') {
                throw new AppwriteException('Missing required parameter: "teamId"');
            }

            if (typeof name === 'undefined') {
                throw new AppwriteException('Missing required parameter: "name"');
            }

            let path = '/teams';
            let payload: Payload = {};

            if (typeof teamId !== 'undefined') {
                payload['teamId'] = teamId;
            }

            if (typeof name !== 'undefined') {
                payload['name'] = name;
            }

            if (typeof roles !== 'undefined') {
                payload['roles'] = roles;
            }

            const uri = new URL(this.client.config.endpoint + path);
            return await this.client.call('post', uri, {
                'content-type': 'application/json',
            }, payload);
        }

        /**
         * Get Team
         *
         * Get a team by its ID. All team members have read access for this resource.
         *
         * @param {string} teamId
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        async get(teamId: string): Promise<Models.Team> {
            if (typeof teamId === 'undefined') {
                throw new AppwriteException('Missing required parameter: "teamId"');
            }

            let path = '/teams/{teamId}'.replace('{teamId}', teamId);
            let payload: Payload = {};

            const uri = new URL(this.client.config.endpoint + path);
            return await this.client.call('get', uri, {
                'content-type': 'application/json',
            }, payload);
        }

        /**
         * Update Team
         *
         * Update a team using its ID. Only members with the owner role can update the
         * team.
         *
         * @param {string} teamId
         * @param {string} name
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        async update(teamId: string, name: string): Promise<Models.Team> {
            if (typeof teamId === 'undefined') {
                throw new AppwriteException('Missing required parameter: "teamId"');
            }

            if (typeof name === 'undefined') {
                throw new AppwriteException('Missing required parameter: "name"');
            }

            let path = '/teams/{teamId}'.replace('{teamId}', teamId);
            let payload: Payload = {};

            if (typeof name !== 'undefined') {
                payload['name'] = name;
            }

            const uri = new URL(this.client.config.endpoint + path);
            return await this.client.call('put', uri, {
                'content-type': 'application/json',
            }, payload);
        }

        /**
         * Delete Team
         *
         * Delete a team using its ID. Only team members with the owner role can
         * delete the team.
         *
         * @param {string} teamId
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        async delete(teamId: string): Promise<{}> {
            if (typeof teamId === 'undefined') {
                throw new AppwriteException('Missing required parameter: "teamId"');
            }

            let path = '/teams/{teamId}'.replace('{teamId}', teamId);
            let payload: Payload = {};

            const uri = new URL(this.client.config.endpoint + path);
            return await this.client.call('delete', uri, {
                'content-type': 'application/json',
            }, payload);
        }

        /**
         * List Team Memberships
         *
         * Use this endpoint to list a team's members using the team's ID. All team
         * members have read access to this endpoint.
         *
         * @param {string} teamId
         * @param {string[]} queries
         * @param {string} search
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        async listMemberships(teamId: string, queries?: string[], search?: string): Promise<Models.MembershipList> {
            if (typeof teamId === 'undefined') {
                throw new AppwriteException('Missing required parameter: "teamId"');
            }

            let path = '/teams/{teamId}/memberships'.replace('{teamId}', teamId);
            let payload: Payload = {};

            if (typeof queries !== 'undefined') {
                payload['queries'] = queries;
            }

            if (typeof search !== 'undefined') {
                payload['search'] = search;
            }

            const uri = new URL(this.client.config.endpoint + path);
            return await this.client.call('get', uri, {
                'content-type': 'application/json',
            }, payload);
        }

        /**
         * Create Team Membership
         *
         * Invite a new member to join your team. If initiated from the client SDK, an
         * email with a link to join the team will be sent to the member's email
         * address and an account will be created for them should they not be signed
         * up already. If initiated from server-side SDKs, the new member will
         * automatically be added to the team.
         * 
         * Use the 'url' parameter to redirect the user from the invitation email back
         * to your app. When the user is redirected, use the [Update Team Membership
         * Status](/docs/client/teams#teamsUpdateMembershipStatus) endpoint to allow
         * the user to accept the invitation to the team. 
         * 
         * Please note that to avoid a [Redirect
         * Attack](https://github.com/OWASP/CheatSheetSeries/blob/master/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.md)
         * the only valid redirect URL's are the once from domains you have set when
         * adding your platforms in the console interface.
         *
         * @param {string} teamId
         * @param {string} email
         * @param {string[]} roles
         * @param {string} url
         * @param {string} name
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        async createMembership(teamId: string, email: string, roles: string[], url: string, name?: string): Promise<Models.Membership> {
            if (typeof teamId === 'undefined') {
                throw new AppwriteException('Missing required parameter: "teamId"');
            }

            if (typeof email === 'undefined') {
                throw new AppwriteException('Missing required parameter: "email"');
            }

            if (typeof roles === 'undefined') {
                throw new AppwriteException('Missing required parameter: "roles"');
            }

            if (typeof url === 'undefined') {
                throw new AppwriteException('Missing required parameter: "url"');
            }

            let path = '/teams/{teamId}/memberships'.replace('{teamId}', teamId);
            let payload: Payload = {};

            if (typeof email !== 'undefined') {
                payload['email'] = email;
            }

            if (typeof roles !== 'undefined') {
                payload['roles'] = roles;
            }

            if (typeof url !== 'undefined') {
                payload['url'] = url;
            }

            if (typeof name !== 'undefined') {
                payload['name'] = name;
            }

            const uri = new URL(this.client.config.endpoint + path);
            return await this.client.call('post', uri, {
                'content-type': 'application/json',
            }, payload);
        }

        /**
         * Get Team Membership
         *
         * Get a team member by the membership unique id. All team members have read
         * access for this resource.
         *
         * @param {string} teamId
         * @param {string} membershipId
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        async getMembership(teamId: string, membershipId: string): Promise<Models.MembershipList> {
            if (typeof teamId === 'undefined') {
                throw new AppwriteException('Missing required parameter: "teamId"');
            }

            if (typeof membershipId === 'undefined') {
                throw new AppwriteException('Missing required parameter: "membershipId"');
            }

            let path = '/teams/{teamId}/memberships/{membershipId}'.replace('{teamId}', teamId).replace('{membershipId}', membershipId);
            let payload: Payload = {};

            const uri = new URL(this.client.config.endpoint + path);
            return await this.client.call('get', uri, {
                'content-type': 'application/json',
            }, payload);
        }

        /**
         * Update Membership Roles
         *
         * Modify the roles of a team member. Only team members with the owner role
         * have access to this endpoint. Learn more about [roles and
         * permissions](/docs/permissions).
         *
         * @param {string} teamId
         * @param {string} membershipId
         * @param {string[]} roles
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        async updateMembershipRoles(teamId: string, membershipId: string, roles: string[]): Promise<Models.Membership> {
            if (typeof teamId === 'undefined') {
                throw new AppwriteException('Missing required parameter: "teamId"');
            }

            if (typeof membershipId === 'undefined') {
                throw new AppwriteException('Missing required parameter: "membershipId"');
            }

            if (typeof roles === 'undefined') {
                throw new AppwriteException('Missing required parameter: "roles"');
            }

            let path = '/teams/{teamId}/memberships/{membershipId}'.replace('{teamId}', teamId).replace('{membershipId}', membershipId);
            let payload: Payload = {};

            if (typeof roles !== 'undefined') {
                payload['roles'] = roles;
            }

            const uri = new URL(this.client.config.endpoint + path);
            return await this.client.call('patch', uri, {
                'content-type': 'application/json',
            }, payload);
        }

        /**
         * Delete Team Membership
         *
         * This endpoint allows a user to leave a team or for a team owner to delete
         * the membership of any other team member. You can also use this endpoint to
         * delete a user membership even if it is not accepted.
         *
         * @param {string} teamId
         * @param {string} membershipId
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        async deleteMembership(teamId: string, membershipId: string): Promise<{}> {
            if (typeof teamId === 'undefined') {
                throw new AppwriteException('Missing required parameter: "teamId"');
            }

            if (typeof membershipId === 'undefined') {
                throw new AppwriteException('Missing required parameter: "membershipId"');
            }

            let path = '/teams/{teamId}/memberships/{membershipId}'.replace('{teamId}', teamId).replace('{membershipId}', membershipId);
            let payload: Payload = {};

            const uri = new URL(this.client.config.endpoint + path);
            return await this.client.call('delete', uri, {
                'content-type': 'application/json',
            }, payload);
        }

        /**
         * Update Team Membership Status
         *
         * Use this endpoint to allow a user to accept an invitation to join a team
         * after being redirected back to your app from the invitation email received
         * by the user.
         * 
         * If the request is successful, a session for the user is automatically
         * created.
         * 
         *
         * @param {string} teamId
         * @param {string} membershipId
         * @param {string} userId
         * @param {string} secret
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        async updateMembershipStatus(teamId: string, membershipId: string, userId: string, secret: string): Promise<Models.Membership> {
            if (typeof teamId === 'undefined') {
                throw new AppwriteException('Missing required parameter: "teamId"');
            }

            if (typeof membershipId === 'undefined') {
                throw new AppwriteException('Missing required parameter: "membershipId"');
            }

            if (typeof userId === 'undefined') {
                throw new AppwriteException('Missing required parameter: "userId"');
            }

            if (typeof secret === 'undefined') {
                throw new AppwriteException('Missing required parameter: "secret"');
            }

            let path = '/teams/{teamId}/memberships/{membershipId}/status'.replace('{teamId}', teamId).replace('{membershipId}', membershipId);
            let payload: Payload = {};

            if (typeof userId !== 'undefined') {
                payload['userId'] = userId;
            }

            if (typeof secret !== 'undefined') {
                payload['secret'] = secret;
            }

            const uri = new URL(this.client.config.endpoint + path);
            return await this.client.call('patch', uri, {
                'content-type': 'application/json',
            }, payload);
        }
};
