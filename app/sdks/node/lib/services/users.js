const Service = require('../service.js');

class Users extends Service {

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
     * @return {}
     */
    async listUsers(search = '', limit = 25, offset = 0, orderType = 'ASC') {
        let path = '/users';
        
        return await this.client.call('get', path, {'content-type': 'application/json'},
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
     * @return {}
     */
    async createUser(email, password, name = '') {
        let path = '/users';
        
        return await this.client.call('post', path, {'content-type': 'application/json'},
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
     * @return {}
     */
    async getUser(userId) {
        let path = '/users/{userId}'.replace(new RegExp('{userId}', 'g'), userId);
        
        return await this.client.call('get', path, {'content-type': 'application/json'},
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
     * @return {}
     */
    async getUserLogs(userId) {
        let path = '/users/{userId}/logs'.replace(new RegExp('{userId}', 'g'), userId);
        
        return await this.client.call('get', path, {'content-type': 'application/json'},
            {
            });
    }

    /**
     * Get User Prefs
     *
     * Get user preferences by its unique ID.
     *
     * @param string userId
     * @throws Exception
     * @return {}
     */
    async getUserPrefs(userId) {
        let path = '/users/{userId}/prefs'.replace(new RegExp('{userId}', 'g'), userId);
        
        return await this.client.call('get', path, {'content-type': 'application/json'},
            {
            });
    }

    /**
     * Update Account Prefs
     *
     * Update user preferences by its unique ID. You can pass only the specific
     * settings you wish to update.
     *
     * @param string userId
     * @param string prefs
     * @throws Exception
     * @return {}
     */
    async updateUserPrefs(userId, prefs) {
        let path = '/users/{userId}/prefs'.replace(new RegExp('{userId}', 'g'), userId);
        
        return await this.client.call('patch', path, {'content-type': 'application/json'},
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
     * @return {}
     */
    async getUserSessions(userId) {
        let path = '/users/{userId}/sessions'.replace(new RegExp('{userId}', 'g'), userId);
        
        return await this.client.call('get', path, {'content-type': 'application/json'},
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
     * @return {}
     */
    async deleteUserSessions(userId) {
        let path = '/users/{userId}/sessions'.replace(new RegExp('{userId}', 'g'), userId);
        
        return await this.client.call('delete', path, {'content-type': 'application/json'},
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
     * @return {}
     */
    async deleteUserSession(userId, sessionId) {
        let path = '/users/{userId}/sessions/:session'.replace(new RegExp('{userId}', 'g'), userId);
        
        return await this.client.call('delete', path, {'content-type': 'application/json'},
            {
                'sessionId': sessionId
            });
    }

    /**
     * Update user status
     *
     * Update user status by its unique ID.
     *
     * @param string userId
     * @param string status
     * @throws Exception
     * @return {}
     */
    async updateUserStatus(userId, status) {
        let path = '/users/{userId}/status'.replace(new RegExp('{userId}', 'g'), userId);
        
        return await this.client.call('patch', path, {'content-type': 'application/json'},
            {
                'status': status
            });
    }
}

module.exports = Users;