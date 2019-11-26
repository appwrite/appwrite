from ..service import Service


class Projects(Service):

    def __init__(self, client):
        super(Projects, self).__init__(client)

    def list_projects(self):
        """List Projects"""

        params = {}
        path = '/projects'

        return self.client.call('get', path, {
            'content-type': 'application/json',
        }, params)

    def create_project(self, name, team_id, description='', logo='', url='', legal_name='', legal_country='', legal_state='', legal_city='', legal_address='', legal_tax_id=''):
        """Create Project"""

        params = {}
        path = '/projects'
        params['name'] = name
        params['teamId'] = team_id
        params['description'] = description
        params['logo'] = logo
        params['url'] = url
        params['legalName'] = legal_name
        params['legalCountry'] = legal_country
        params['legalState'] = legal_state
        params['legalCity'] = legal_city
        params['legalAddress'] = legal_address
        params['legalTaxId'] = legal_tax_id

        return self.client.call('post', path, {
            'content-type': 'application/json',
        }, params)

    def get_project(self, project_id):
        """Get Project"""

        params = {}
        path = '/projects/{projectId}'
        path.replace('{projectId}', project_id)                

        return self.client.call('get', path, {
            'content-type': 'application/json',
        }, params)

    def update_project(self, project_id, name, description='', logo='', url='', legal_name='', legal_country='', legal_state='', legal_city='', legal_address='', legal_tax_id=''):
        """Update Project"""

        params = {}
        path = '/projects/{projectId}'
        path.replace('{projectId}', project_id)                
        params['name'] = name
        params['description'] = description
        params['logo'] = logo
        params['url'] = url
        params['legalName'] = legal_name
        params['legalCountry'] = legal_country
        params['legalState'] = legal_state
        params['legalCity'] = legal_city
        params['legalAddress'] = legal_address
        params['legalTaxId'] = legal_tax_id

        return self.client.call('patch', path, {
            'content-type': 'application/json',
        }, params)

    def delete_project(self, project_id):
        """Delete Project"""

        params = {}
        path = '/projects/{projectId}'
        path.replace('{projectId}', project_id)                

        return self.client.call('delete', path, {
            'content-type': 'application/json',
        }, params)

    def list_keys(self, project_id):
        """List Keys"""

        params = {}
        path = '/projects/{projectId}/keys'
        path.replace('{projectId}', project_id)                

        return self.client.call('get', path, {
            'content-type': 'application/json',
        }, params)

    def create_key(self, project_id, name, scopes):
        """Create Key"""

        params = {}
        path = '/projects/{projectId}/keys'
        path.replace('{projectId}', project_id)                
        params['name'] = name
        params['scopes'] = scopes

        return self.client.call('post', path, {
            'content-type': 'application/json',
        }, params)

    def get_key(self, project_id, key_id):
        """Get Key"""

        params = {}
        path = '/projects/{projectId}/keys/{keyId}'
        path.replace('{projectId}', project_id)                
        path.replace('{keyId}', key_id)                

        return self.client.call('get', path, {
            'content-type': 'application/json',
        }, params)

    def update_key(self, project_id, key_id, name, scopes):
        """Update Key"""

        params = {}
        path = '/projects/{projectId}/keys/{keyId}'
        path.replace('{projectId}', project_id)                
        path.replace('{keyId}', key_id)                
        params['name'] = name
        params['scopes'] = scopes

        return self.client.call('put', path, {
            'content-type': 'application/json',
        }, params)

    def delete_key(self, project_id, key_id):
        """Delete Key"""

        params = {}
        path = '/projects/{projectId}/keys/{keyId}'
        path.replace('{projectId}', project_id)                
        path.replace('{keyId}', key_id)                

        return self.client.call('delete', path, {
            'content-type': 'application/json',
        }, params)

    def update_project_o_auth(self, project_id, provider, app_id='', secret=''):
        """Update Project OAuth"""

        params = {}
        path = '/projects/{projectId}/oauth'
        path.replace('{projectId}', project_id)                
        params['provider'] = provider
        params['appId'] = app_id
        params['secret'] = secret

        return self.client.call('patch', path, {
            'content-type': 'application/json',
        }, params)

    def list_platforms(self, project_id):
        """List Platforms"""

        params = {}
        path = '/projects/{projectId}/platforms'
        path.replace('{projectId}', project_id)                

        return self.client.call('get', path, {
            'content-type': 'application/json',
        }, params)

    def create_platform(self, project_id, type, name, key='', store='', url=''):
        """Create Platform"""

        params = {}
        path = '/projects/{projectId}/platforms'
        path.replace('{projectId}', project_id)                
        params['type'] = type
        params['name'] = name
        params['key'] = key
        params['store'] = store
        params['url'] = url

        return self.client.call('post', path, {
            'content-type': 'application/json',
        }, params)

    def get_platform(self, project_id, platform_id):
        """Get Platform"""

        params = {}
        path = '/projects/{projectId}/platforms/{platformId}'
        path.replace('{projectId}', project_id)                
        path.replace('{platformId}', platform_id)                

        return self.client.call('get', path, {
            'content-type': 'application/json',
        }, params)

    def update_platform(self, project_id, platform_id, name, key='', store='', url=''):
        """Update Platform"""

        params = {}
        path = '/projects/{projectId}/platforms/{platformId}'
        path.replace('{projectId}', project_id)                
        path.replace('{platformId}', platform_id)                
        params['name'] = name
        params['key'] = key
        params['store'] = store
        params['url'] = url

        return self.client.call('put', path, {
            'content-type': 'application/json',
        }, params)

    def delete_platform(self, project_id, platform_id):
        """Delete Platform"""

        params = {}
        path = '/projects/{projectId}/platforms/{platformId}'
        path.replace('{projectId}', project_id)                
        path.replace('{platformId}', platform_id)                

        return self.client.call('delete', path, {
            'content-type': 'application/json',
        }, params)

    def list_tasks(self, project_id):
        """List Tasks"""

        params = {}
        path = '/projects/{projectId}/tasks'
        path.replace('{projectId}', project_id)                

        return self.client.call('get', path, {
            'content-type': 'application/json',
        }, params)

    def create_task(self, project_id, name, status, schedule, security, http_method, http_url, http_headers={}, http_user='', http_pass=''):
        """Create Task"""

        params = {}
        path = '/projects/{projectId}/tasks'
        path.replace('{projectId}', project_id)                
        params['name'] = name
        params['status'] = status
        params['schedule'] = schedule
        params['security'] = security
        params['httpMethod'] = http_method
        params['httpUrl'] = http_url
        params['httpHeaders'] = http_headers
        params['httpUser'] = http_user
        params['httpPass'] = http_pass

        return self.client.call('post', path, {
            'content-type': 'application/json',
        }, params)

    def get_task(self, project_id, task_id):
        """Get Task"""

        params = {}
        path = '/projects/{projectId}/tasks/{taskId}'
        path.replace('{projectId}', project_id)                
        path.replace('{taskId}', task_id)                

        return self.client.call('get', path, {
            'content-type': 'application/json',
        }, params)

    def update_task(self, project_id, task_id, name, status, schedule, security, http_method, http_url, http_headers={}, http_user='', http_pass=''):
        """Update Task"""

        params = {}
        path = '/projects/{projectId}/tasks/{taskId}'
        path.replace('{projectId}', project_id)                
        path.replace('{taskId}', task_id)                
        params['name'] = name
        params['status'] = status
        params['schedule'] = schedule
        params['security'] = security
        params['httpMethod'] = http_method
        params['httpUrl'] = http_url
        params['httpHeaders'] = http_headers
        params['httpUser'] = http_user
        params['httpPass'] = http_pass

        return self.client.call('put', path, {
            'content-type': 'application/json',
        }, params)

    def delete_task(self, project_id, task_id):
        """Delete Task"""

        params = {}
        path = '/projects/{projectId}/tasks/{taskId}'
        path.replace('{projectId}', project_id)                
        path.replace('{taskId}', task_id)                

        return self.client.call('delete', path, {
            'content-type': 'application/json',
        }, params)

    def get_project_usage(self, project_id):
        """Get Project"""

        params = {}
        path = '/projects/{projectId}/usage'
        path.replace('{projectId}', project_id)                

        return self.client.call('get', path, {
            'content-type': 'application/json',
        }, params)

    def list_webhooks(self, project_id):
        """List Webhooks"""

        params = {}
        path = '/projects/{projectId}/webhooks'
        path.replace('{projectId}', project_id)                

        return self.client.call('get', path, {
            'content-type': 'application/json',
        }, params)

    def create_webhook(self, project_id, name, events, url, security, http_user='', http_pass=''):
        """Create Webhook"""

        params = {}
        path = '/projects/{projectId}/webhooks'
        path.replace('{projectId}', project_id)                
        params['name'] = name
        params['events'] = events
        params['url'] = url
        params['security'] = security
        params['httpUser'] = http_user
        params['httpPass'] = http_pass

        return self.client.call('post', path, {
            'content-type': 'application/json',
        }, params)

    def get_webhook(self, project_id, webhook_id):
        """Get Webhook"""

        params = {}
        path = '/projects/{projectId}/webhooks/{webhookId}'
        path.replace('{projectId}', project_id)                
        path.replace('{webhookId}', webhook_id)                

        return self.client.call('get', path, {
            'content-type': 'application/json',
        }, params)

    def update_webhook(self, project_id, webhook_id, name, events, url, security, http_user='', http_pass=''):
        """Update Webhook"""

        params = {}
        path = '/projects/{projectId}/webhooks/{webhookId}'
        path.replace('{projectId}', project_id)                
        path.replace('{webhookId}', webhook_id)                
        params['name'] = name
        params['events'] = events
        params['url'] = url
        params['security'] = security
        params['httpUser'] = http_user
        params['httpPass'] = http_pass

        return self.client.call('put', path, {
            'content-type': 'application/json',
        }, params)

    def delete_webhook(self, project_id, webhook_id):
        """Delete Webhook"""

        params = {}
        path = '/projects/{projectId}/webhooks/{webhookId}'
        path.replace('{projectId}', project_id)                
        path.replace('{webhookId}', webhook_id)                

        return self.client.call('delete', path, {
            'content-type': 'application/json',
        }, params)
