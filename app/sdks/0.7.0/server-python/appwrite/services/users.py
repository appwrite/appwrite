from ..service import Service


class Users(Service):

    def __init__(self, client):
        super(Users, self).__init__(client)

    def list(self, search='', limit=25, offset=0, order_type='ASC'):
        """List Users"""

        params = {}
        path = '/users'
        params['search'] = search
        params['limit'] = limit
        params['offset'] = offset
        params['orderType'] = order_type

        return self.client.call('get', path, {
            'content-type': 'application/json',
        }, params)

    def create(self, email, password, name=''):
        """Create User"""

        params = {}
        path = '/users'
        params['email'] = email
        params['password'] = password
        params['name'] = name

        return self.client.call('post', path, {
            'content-type': 'application/json',
        }, params)

    def get(self, user_id):
        """Get User"""

        params = {}
        path = '/users/{userId}'
        path = path.replace('{userId}', user_id)                

        return self.client.call('get', path, {
            'content-type': 'application/json',
        }, params)

    def delete_user(self, user_id):
        """Delete User"""

        params = {}
        path = '/users/{userId}'
        path = path.replace('{userId}', user_id)                

        return self.client.call('delete', path, {
            'content-type': 'application/json',
        }, params)

    def get_logs(self, user_id):
        """Get User Logs"""

        params = {}
        path = '/users/{userId}/logs'
        path = path.replace('{userId}', user_id)                

        return self.client.call('get', path, {
            'content-type': 'application/json',
        }, params)

    def get_prefs(self, user_id):
        """Get User Preferences"""

        params = {}
        path = '/users/{userId}/prefs'
        path = path.replace('{userId}', user_id)                

        return self.client.call('get', path, {
            'content-type': 'application/json',
        }, params)

    def update_prefs(self, user_id, prefs):
        """Update User Preferences"""

        params = {}
        path = '/users/{userId}/prefs'
        path = path.replace('{userId}', user_id)                
        params['prefs'] = prefs

        return self.client.call('patch', path, {
            'content-type': 'application/json',
        }, params)

    def get_sessions(self, user_id):
        """Get User Sessions"""

        params = {}
        path = '/users/{userId}/sessions'
        path = path.replace('{userId}', user_id)                

        return self.client.call('get', path, {
            'content-type': 'application/json',
        }, params)

    def delete_sessions(self, user_id):
        """Delete User Sessions"""

        params = {}
        path = '/users/{userId}/sessions'
        path = path.replace('{userId}', user_id)                

        return self.client.call('delete', path, {
            'content-type': 'application/json',
        }, params)

    def delete_session(self, user_id, session_id):
        """Delete User Session"""

        params = {}
        path = '/users/{userId}/sessions/{sessionId}'
        path = path.replace('{userId}', user_id)                
        path = path.replace('{sessionId}', session_id)                

        return self.client.call('delete', path, {
            'content-type': 'application/json',
        }, params)

    def update_status(self, user_id, status):
        """Update User Status"""

        params = {}
        path = '/users/{userId}/status'
        path = path.replace('{userId}', user_id)                
        params['status'] = status

        return self.client.call('patch', path, {
            'content-type': 'application/json',
        }, params)
