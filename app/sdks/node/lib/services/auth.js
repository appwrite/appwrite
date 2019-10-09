const Service = require('../service.js');

class Auth extends Service {

    /**
     * Login User
     *
     * /docs/references/auth/login.md
     *
     * @param string email
     * @param string password
     * @param string success
     * @param string failure
     * @throws Exception
     * @return {}
     */
    async login(email, password, success, failure) {
        let path = '/auth/login';
        
        return await this.client.call('post', path, {'content-type': 'application/json'},
            {
                'email': email,
                'password': password,
                'success': success,
                'failure': failure
            });
    }

    /**
     * Logout Current Session
     *
     * /docs/references/auth/logout.md
     *
     * @throws Exception
     * @return {}
     */
    async logout() {
        let path = '/auth/logout';
        
        return await this.client.call('delete', path, {'content-type': 'application/json'},
            {
            });
    }

    /**
     * Logout Specific Session
     *
     * /docs/references/auth/logout-by-session.md
     *
     * @param string id
     * @throws Exception
     * @return {}
     */
    async logoutBySession(id) {
        let path = '/auth/logout/{id}'.replace(new RegExp('{id}', 'g'), id);
        
        return await this.client.call('delete', path, {'content-type': 'application/json'},
            {
            });
    }

    /**
     * OAuth Login
     *
     * @param string provider
     * @param string success
     * @param string failure
     * @throws Exception
     * @return {}
     */
    async oauth(provider, success = '', failure = '') {
        let path = '/auth/oauth/{provider}'.replace(new RegExp('{provider}', 'g'), provider);
        
        return await this.client.call('get', path, {'content-type': 'application/json'},
            {
                'success': success,
                'failure': failure
            });
    }

    /**
     * Password Recovery
     *
     * /docs/references/auth/recovery.md
     *
     * @param string email
     * @param string reset
     * @throws Exception
     * @return {}
     */
    async recovery(email, reset) {
        let path = '/auth/recovery';
        
        return await this.client.call('post', path, {'content-type': 'application/json'},
            {
                'email': email,
                'reset': reset
            });
    }

    /**
     * Password Reset
     *
     * /docs/references/auth/recovery-reset.md
     *
     * @param string userId
     * @param string token
     * @param string passwordA
     * @param string passwordB
     * @throws Exception
     * @return {}
     */
    async recoveryReset(userId, token, passwordA, passwordB) {
        let path = '/auth/recovery/reset';
        
        return await this.client.call('put', path, {'content-type': 'application/json'},
            {
                'userId': userId,
                'token': token,
                'password-a': passwordA,
                'password-b': passwordB
            });
    }

    /**
     * Register User
     *
     * /docs/references/auth/register.md
     *
     * @param string email
     * @param string password
     * @param string confirm
     * @param string success
     * @param string failure
     * @param string name
     * @throws Exception
     * @return {}
     */
    async register(email, password, confirm, success = '', failure = '', name = '') {
        let path = '/auth/register';
        
        return await this.client.call('post', path, {'content-type': 'application/json'},
            {
                'email': email,
                'password': password,
                'confirm': confirm,
                'success': success,
                'failure': failure,
                'name': name
            });
    }

    /**
     * Confirm User
     *
     * /docs/references/auth/confirm.md
     *
     * @param string userId
     * @param string token
     * @throws Exception
     * @return {}
     */
    async confirm(userId, token) {
        let path = '/auth/register/confirm';
        
        return await this.client.call('post', path, {'content-type': 'application/json'},
            {
                'userId': userId,
                'token': token
            });
    }

    /**
     * Resend Confirmation
     *
     * /docs/references/auth/confirm-resend.md
     *
     * @param string confirm
     * @throws Exception
     * @return {}
     */
    async confirmResend(confirm) {
        let path = '/auth/register/confirm/resend';
        
        return await this.client.call('post', path, {'content-type': 'application/json'},
            {
                'confirm': confirm
            });
    }
}

module.exports = Auth;