from ..service import Service


class Auth(Service):

    def login(self, email, password, success='', failure=''):
        """Login User"""

        params = {}
        path = '/auth/login'
        params['email'] = email
        params['password'] = password
        params['success'] = success
        params['failure'] = failure

        return self.client.call('post', path, {
        }, params)

    def logout(self):
        """Logout Current Session"""

        params = {}
        path = '/auth/logout'

        return self.client.call('delete', path, {
        }, params)

    def logout_by_session(self, id):
        """Logout Specific Session"""

        params = {}
        path = '/auth/logout/{id}'
        path.replace('{id}', id)                

        return self.client.call('delete', path, {
        }, params)

    def oauth_callback(self, project_id, provider, code, state=''):
        """OAuth Callback"""

        params = {}
        path = '/auth/oauth/callback/{provider}/{projectId}'
        path.replace('{projectId}', project_id)                
        path.replace('{provider}', provider)                
        params['code'] = code
        params['state'] = state

        return self.client.call('get', path, {
        }, params)

    def oauth(self, provider, success='', failure=''):
        """OAuth Login"""

        params = {}
        path = '/auth/oauth/{provider}'
        path.replace('{provider}', provider)                
        params['success'] = success
        params['failure'] = failure

        return self.client.call('get', path, {
        }, params)

    def recovery(self, email, redirect):
        """Password Recovery"""

        params = {}
        path = '/auth/recovery'
        params['email'] = email
        params['redirect'] = redirect

        return self.client.call('post', path, {
        }, params)

    def recovery_reset(self, user_id, token, password_a, password_b):
        """Password Reset"""

        params = {}
        path = '/auth/recovery/reset'
        params['userId'] = user_id
        params['token'] = token
        params['password-a'] = password_a
        params['password-b'] = password_b

        return self.client.call('put', path, {
        }, params)

    def register(self, email, password, redirect, success, failure, name=''):
        """Register User"""

        params = {}
        path = '/auth/register'
        params['email'] = email
        params['password'] = password
        params['redirect'] = redirect
        params['name'] = name
        params['success'] = success
        params['failure'] = failure

        return self.client.call('post', path, {
        }, params)

    def confirm(self, user_id, token):
        """Confirm User"""

        params = {}
        path = '/auth/register/confirm'
        params['userId'] = user_id
        params['token'] = token

        return self.client.call('post', path, {
        }, params)

    def confirm_resend(self, redirect):
        """Resend Confirmation"""

        params = {}
        path = '/auth/register/confirm/resend'
        params['redirect'] = redirect

        return self.client.call('post', path, {
        }, params)
