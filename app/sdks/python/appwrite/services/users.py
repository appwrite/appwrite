from ..service import Service


class Users(Service):

    def __init__(self, client):
        super(Users, self).__init__(client)

    def list_users(self, search='', limit=25, offset=0, order_type='ASC'):
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

    def create_user(self, email, password, name=''):
        """Create User"""

        params = {}
        path = '/users'
        params['email'] = email
        params['password'] = password
        params['name'] = name

        return self.client.call('post', path, {
            'content-type': 'application/json',
        }, params)

    def get_user(self, user_id):
        """Get User"""

        params = {}
        path = '/users/{userId}'
        path.replace('{userId}', user_id)                

        return self.client.call('get', path, {
            'content-type': 'application/json',
        }, params)

    def get_user_logs(self, user_id):
        """Get User Logs"""

        params = {}
        path = '/users/{userId}/logs'
        path.replace('{userId}', user_id)                

        return self.client.call('get', path, {
            'content-type': 'application/json',
        }, params)

    def get_user_prefs(self, user_id):
        """Get User Prefs"""

        params = {}
        path = '/users/{userId}/prefs'
        path.replace('{userId}', user_id)                

        return self.client.call('get', path, {
            'content-type': 'application/json',
        }, params)

    def update_user_prefs(self, user_id, prefs):
        """Update User Prefs"""

        params = {}
        path = '/users/{userId}/prefs'
        path.replace('{userId}', user_id)                
        params['prefs'] = prefs

        return self.client.call('patch', path, {
            'content-type': 'application/json',
        }, params)

    def get_user_sessions(self, user_id):
        """Get User Sessions"""

        params = {}
        path = '/users/{userId}/sessions'
        path.replace('{userId}', user_id)                

        return self.client.call('get', path, {
            'content-type': 'application/json',
        }, params)

    def delete_user_sessions(self, user_id):
        """Delete User Sessions"""

        params = {}
        path = '/users/{userId}/sessions'
        path.replace('{userId}', user_id)                

        return self.client.call('delete', path, {
            'content-type': 'application/json',
        }, params)

    def delete_user_session(self, user_id, session_id):
        """Delete User Session"""

        params = {}
        path = '/users/{userId}/sessions/:session'
        path.replace('{userId}', user_id)                
        params['sessionId'] = session_id

        return self.client.call('delete', path, {
            'content-type': 'application/json',
        }, params)

    def update_user_status(self, user_id, status):
        """Update User Status"""

        params = {}
        path = '/users/{userId}/status'
        path.replace('{userId}', user_id)                
        params['status'] = status

        return self.client.call('patch', path, {
            'content-type': 'application/json',
        }, params)
