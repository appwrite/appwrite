import { Service } from "../service.ts";
import { DocumentData } from '../client.ts'

export class Users extends Service {

    /**
     * List Users
     *
     * Get a list of all the project users. You can use the query params to filter
     * your results.
     *
     * @param string search
     * @param number limit
     * @param number offset
     * @param string orderType
     * @throws Exception
     * @return Promise<string>
     */
    async list(search: string = '', limit: number = 25, offset: number = 0, orderType: string = 'ASC'): Promise<string> {
        let path = '/users';
        
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
     * Create User
     *
     * Create a new user.
     *
     * @param string email
     * @param string password
     * @param string name
     * @throws Exception
     * @return Promise<string>
     */
    async create(email: string, password: string, name: string = ''): Promise<string> {
        let path = '/users';
        
        return await this.client.call('post', path, {
                    'content-type': 'application/json',
               },
               {
                'email': email,
                'password': password,
                'name': name
            });
    }

    /**
     * Get User
     *
     * Get user by its unique ID.
     *
     * @param string userId
     * @throws Exception
     * @return Promise<string>
     */
    async get(userId: string): Promise<string> {
        let path = '/users/{userId}'.replace(new RegExp('{userId}', 'g'), userId);
        
        return await this.client.call('get', path, {
                    'content-type': 'application/json',
               },
               {
            });
    }

    /**
     * Delete User
     *
     * Delete a user by its unique ID.
     *
     * @param string userId
     * @throws Exception
     * @return Promise<string>
     */
    async deleteUser(userId: string): Promise<string> {
        let path = '/users/{userId}'.replace(new RegExp('{userId}', 'g'), userId);
        
        return await this.client.call('delete', path, {
                    'content-type': 'application/json',
               },
               {
            });
    }

    /**
     * Get User Logs
     *
     * Get user activity logs list by its unique ID.
     *
     * @param string userId
     * @throws Exception
     * @return Promise<string>
     */
    async getLogs(userId: string): Promise<string> {
        let path = '/users/{userId}/logs'.replace(new RegExp('{userId}', 'g'), userId);
        
        return await this.client.call('get', path, {
                    'content-type': 'application/json',
               },
               {
            });
    }

    /**
     * Get User Preferences
     *
     * Get user preferences by its unique ID.
     *
     * @param string userId
     * @throws Exception
     * @return Promise<string>
     */
    async getPrefs(userId: string): Promise<string> {
        let path = '/users/{userId}/prefs'.replace(new RegExp('{userId}', 'g'), userId);
        
        return await this.client.call('get', path, {
                    'content-type': 'application/json',
               },
               {
            });
    }

    /**
     * Update User Preferences
     *
     * Update user preferences by its unique ID. You can pass only the specific
     * settings you wish to update.
     *
     * @param string userId
     * @param DocumentData prefs
     * @throws Exception
     * @return Promise<string>
     */
    async updatePrefs(userId: string, prefs: DocumentData): Promise<string> {
        let path = '/users/{userId}/prefs'.replace(new RegExp('{userId}', 'g'), userId);
        
        return await this.client.call('patch', path, {
                    'content-type': 'application/json',
               },
               {
                'prefs': prefs
            });
    }

    /**
     * Get User Sessions
     *
     * Get user sessions list by its unique ID.
     *
     * @param string userId
     * @throws Exception
     * @return Promise<string>
     */
    async getSessions(userId: string): Promise<string> {
        let path = '/users/{userId}/sessions'.replace(new RegExp('{userId}', 'g'), userId);
        
        return await this.client.call('get', path, {
                    'content-type': 'application/json',
               },
               {
            });
    }

    /**
     * Delete User Sessions
     *
     * Delete all user sessions by its unique ID.
     *
     * @param string userId
     * @throws Exception
     * @return Promise<string>
     */
    async deleteSessions(userId: string): Promise<string> {
        let path = '/users/{userId}/sessions'.replace(new RegExp('{userId}', 'g'), userId);
        
        return await this.client.call('delete', path, {
                    'content-type': 'application/json',
               },
               {
            });
    }

    /**
     * Delete User Session
     *
     * Delete user sessions by its unique ID.
     *
     * @param string userId
     * @param string sessionId
     * @throws Exception
     * @return Promise<string>
     */
    async deleteSession(userId: string, sessionId: string): Promise<string> {
        let path = '/users/{userId}/sessions/{sessionId}'.replace(new RegExp('{userId}', 'g'), userId).replace(new RegExp('{sessionId}', 'g'), sessionId);
        
        return await this.client.call('delete', path, {
                    'content-type': 'application/json',
               },
               {
            });
    }

    /**
     * Update User Status
     *
     * Update user status by its unique ID.
     *
     * @param string userId
     * @param string status
     * @throws Exception
     * @return Promise<string>
     */
    async updateStatus(userId: string, status: string): Promise<string> {
        let path = '/users/{userId}/status'.replace(new RegExp('{userId}', 'g'), userId);
        
        return await this.client.call('patch', path, {
                    'content-type': 'application/json',
               },
               {
                'status': status
            });
    }
}