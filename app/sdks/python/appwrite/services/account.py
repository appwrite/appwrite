from ..service import Service


class Account(Service):

    def __init__(self, client):
        super(Account, self).__init__(client)

    def get(self):
        """Get Account"""

        params = {}
        path = '/account'

        return self.client.call('get', path, {
            'content-type': 'application/json',
        }, params)

    def delete(self):
        """Delete Account"""

        params = {}
        path = '/account'

        return self.client.call('delete', path, {
            'content-type': 'application/json',
        }, params)

    def update_email(self, email, password):
        """Update Account Email"""

        params = {}
        path = '/account/email'
        params['email'] = email
        params['password'] = password

        return self.client.call('patch', path, {
            'content-type': 'application/json',
        }, params)

    def update_name(self, name):
        """Update Account Name"""

        params = {}
        path = '/account/name'
        params['name'] = name

        return self.client.call('patch', path, {
            'content-type': 'application/json',
        }, params)

    def update_password(self, password, old_password):
        """Update Account Password"""

        params = {}
        path = '/account/password'
        params['password'] = password
        params['old-password'] = old_password

        return self.client.call('patch', path, {
            'content-type': 'application/json',
        }, params)

    def get_prefs(self):
        """Get Account Preferences"""

        params = {}
        path = '/account/prefs'

        return self.client.call('get', path, {
            'content-type': 'application/json',
        }, params)

    def update_prefs(self, prefs):
        """Update Account Prefs"""

        params = {}
        path = '/account/prefs'
        params['prefs'] = prefs

        return self.client.call('patch', path, {
            'content-type': 'application/json',
        }, params)

    def get_security(self):
        """Get Account Security Log"""

        params = {}
        path = '/account/security'

        return self.client.call('get', path, {
            'content-type': 'application/json',
        }, params)

    def get_sessions(self):
        """Get Account Active Sessions"""

        params = {}
        path = '/account/sessions'

        return self.client.call('get', path, {
            'content-type': 'application/json',
        }, params)
