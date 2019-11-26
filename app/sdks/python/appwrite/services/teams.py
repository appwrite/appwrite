from ..service import Service


class Teams(Service):

    def __init__(self, client):
        super(Teams, self).__init__(client)

    def list_teams(self, search='', limit=25, offset=0, order_type='ASC'):
        """List Teams"""

        params = {}
        path = '/teams'
        params['search'] = search
        params['limit'] = limit
        params['offset'] = offset
        params['orderType'] = order_type

        return self.client.call('get', path, {
            'content-type': 'application/json',
        }, params)

    def create_team(self, name, roles=[]):
        """Create Team"""

        params = {}
        path = '/teams'
        params['name'] = name
        params['roles'] = roles

        return self.client.call('post', path, {
            'content-type': 'application/json',
        }, params)

    def get_team(self, team_id):
        """Get Team"""

        params = {}
        path = '/teams/{teamId}'
        path.replace('{teamId}', team_id)                

        return self.client.call('get', path, {
            'content-type': 'application/json',
        }, params)

    def update_team(self, team_id, name):
        """Update Team"""

        params = {}
        path = '/teams/{teamId}'
        path.replace('{teamId}', team_id)                
        params['name'] = name

        return self.client.call('put', path, {
            'content-type': 'application/json',
        }, params)

    def delete_team(self, team_id):
        """Delete Team"""

        params = {}
        path = '/teams/{teamId}'
        path.replace('{teamId}', team_id)                

        return self.client.call('delete', path, {
            'content-type': 'application/json',
        }, params)

    def get_team_members(self, team_id):
        """Get Team Members"""

        params = {}
        path = '/teams/{teamId}/members'
        path.replace('{teamId}', team_id)                

        return self.client.call('get', path, {
            'content-type': 'application/json',
        }, params)

    def create_team_membership(self, team_id, email, roles, redirect, name=''):
        """Create Team Membership"""

        params = {}
        path = '/teams/{teamId}/memberships'
        path.replace('{teamId}', team_id)                
        params['email'] = email
        params['name'] = name
        params['roles'] = roles
        params['redirect'] = redirect

        return self.client.call('post', path, {
            'content-type': 'application/json',
        }, params)

    def delete_team_membership(self, team_id, invite_id):
        """Delete Team Membership"""

        params = {}
        path = '/teams/{teamId}/memberships/{inviteId}'
        path.replace('{teamId}', team_id)                
        path.replace('{inviteId}', invite_id)                

        return self.client.call('delete', path, {
            'content-type': 'application/json',
        }, params)

    def create_team_membership_resend(self, team_id, invite_id, redirect):
        """Create Team Membership (Resend)"""

        params = {}
        path = '/teams/{teamId}/memberships/{inviteId}/resend'
        path.replace('{teamId}', team_id)                
        path.replace('{inviteId}', invite_id)                
        params['redirect'] = redirect

        return self.client.call('post', path, {
            'content-type': 'application/json',
        }, params)

    def update_team_membership_status(self, team_id, invite_id, user_id, secret, success='', failure=''):
        """Update Team Membership Status"""

        params = {}
        path = '/teams/{teamId}/memberships/{inviteId}/status'
        path.replace('{teamId}', team_id)                
        path.replace('{inviteId}', invite_id)                
        params['userId'] = user_id
        params['secret'] = secret
        params['success'] = success
        params['failure'] = failure

        return self.client.call('patch', path, {
            'content-type': 'application/json',
        }, params)
