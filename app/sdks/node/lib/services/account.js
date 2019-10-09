const Service = require('../service.js');

class Account extends Service {

    /**
     * Get Account
     *
     * /docs/references/account/get.md
     *
     * @throws Exception
     * @return {}
     */
    async get() {
        let path = '/account';
        
        return await this.client.call('get', path, {'content-type': 'application/json'},
            {
            });
    }

    /**
     * Delete Account
     *
     * /docs/references/account/delete.md
     *
     * @throws Exception
     * @return {}
     */
    async delete() {
        let path = '/account';
        
        return await this.client.call('delete', path, {'content-type': 'application/json'},
            {
            });
    }

    /**
     * Update Account Email
     *
     * /docs/references/account/update-email.md
     *
     * @param string email
     * @param string password
     * @throws Exception
     * @return {}
     */
    async updateEmail(email, password) {
        let path = '/account/email';
        
        return await this.client.call('patch', path, {'content-type': 'application/json'},
            {
                'email': email,
                'password': password
            });
    }

    /**
     * Update Account Name
     *
     * /docs/references/account/update-name.md
     *
     * @param string name
     * @throws Exception
     * @return {}
     */
    async updateName(name) {
        let path = '/account/name';
        
        return await this.client.call('patch', path, {'content-type': 'application/json'},
            {
                'name': name
            });
    }

    /**
     * Update Account Password
     *
     * /docs/references/account/update-password.md
     *
     * @param string password
     * @param string oldPassword
     * @throws Exception
     * @return {}
     */
    async updatePassword(password, oldPassword) {
        let path = '/account/password';
        
        return await this.client.call('patch', path, {'content-type': 'application/json'},
            {
                'password': password,
                'old-password': oldPassword
            });
    }

    /**
     * Get Account Preferences
     *
     * /docs/references/account/get-prefs.md
     *
     * @throws Exception
     * @return {}
     */
    async getPrefs() {
        let path = '/account/prefs';
        
        return await this.client.call('get', path, {'content-type': 'application/json'},
            {
            });
    }

    /**
     * Update Account Prefs
     *
     * /docs/references/account/update-prefs.md
     *
     * @param string prefs
     * @throws Exception
     * @return {}
     */
    async updatePrefs(prefs) {
        let path = '/account/prefs';
        
        return await this.client.call('patch', path, {'content-type': 'application/json'},
            {
                'prefs': prefs
            });
    }

    /**
     * Get Account Security Log
     *
     * /docs/references/account/get-security.md
     *
     * @throws Exception
     * @return {}
     */
    async getSecurity() {
        let path = '/account/security';
        
        return await this.client.call('get', path, {'content-type': 'application/json'},
            {
            });
    }

    /**
     * Get Account Active Sessions
     *
     * /docs/references/account/get-sessions.md
     *
     * @throws Exception
     * @return {}
     */
    async getSessions() {
        let path = '/account/sessions';
        
        return await this.client.call('get', path, {'content-type': 'application/json'},
            {
            });
    }
}

module.exports = Account;