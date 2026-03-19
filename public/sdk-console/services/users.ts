import { Service } from '../service';
import { AppwriteException, Client } from '../client';
import type { Models } from '../models';
import type { UploadProgress, Payload } from '../client';

export class Users extends Service {

     constructor(client: Client)
     {
        super(client);
     }

        /**
         * List Users
         *
         * Get a list of all the project's users. You can use the query params to
         * filter your results.
         *
         * @param {string[]} queries
         * @param {string} search
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        async list<Preferences extends Models.Preferences>(queries?: string[], search?: string): Promise<Models.UserList<Preferences>> {
            let path = '/users';
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
         * Get User
         *
         * Get a user by its unique ID.
         *
         * @param {string} userId
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        async get<Preferences extends Models.Preferences>(userId: string): Promise<Models.User<Preferences>> {
            if (typeof userId === 'undefined') {
                throw new AppwriteException('Missing required parameter: "userId"');
            }

            let path = '/users/{userId}'.replace('{userId}', userId);
            let payload: Payload = {};

            const uri = new URL(this.client.config.endpoint + path);
            return await this.client.call('get', uri, {
                'content-type': 'application/json',
            }, payload);
        }

        /**
         * Delete User
         *
         * Delete a user by its unique ID, thereby releasing it from your project.
         * All user-related resources (sessions, memberships, targets) are also
         * deleted. Since this action is irreversible, use it with caution.
         *
         * @param {string} userId
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        async delete(userId: string): Promise<{}> {
            if (typeof userId === 'undefined') {
                throw new AppwriteException('Missing required parameter: "userId"');
            }

            let path = '/users/{userId}'.replace('{userId}', userId);
            let payload: Payload = {};

            const uri = new URL(this.client.config.endpoint + path);
            return await this.client.call('delete', uri, {
                'content-type': 'application/json',
            }, payload);
        }

        /**
         * Update User Status
         *
         * Update the user status by its unique ID. Use this endpoint to block or
         * unblock a user.
         *
         * @param {string} userId
         * @param {boolean} status
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        async updateStatus<Preferences extends Models.Preferences>(userId: string, status: boolean): Promise<Models.User<Preferences>> {
            if (typeof userId === 'undefined') {
                throw new AppwriteException('Missing required parameter: "userId"');
            }

            if (typeof status === 'undefined') {
                throw new AppwriteException('Missing required parameter: "status"');
            }

            let path = '/users/{userId}/status'.replace('{userId}', userId);
            let payload: Payload = {};

            payload['status'] = status;

            const uri = new URL(this.client.config.endpoint + path);
            return await this.client.call('patch', uri, {
                'content-type': 'application/json',
            }, payload);
        }
};
