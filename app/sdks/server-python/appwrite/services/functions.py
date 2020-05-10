from ..service import Service


class Functions(Service):

    def __init__(self, client):
        super(Functions, self).__init__(client)

    def list(self, search='', limit=25, offset=0, order_type='ASC'):
        """List Functions"""

        params = {}
        path = '/functions'
        params['search'] = search
        params['limit'] = limit
        params['offset'] = offset
        params['orderType'] = order_type

        return self.client.call('get', path, {
            'content-type': 'application/json',
        }, params)

    def create(self, name, vars=[], trigger='event', events=[], schedule='', timeout=10):
        """Create Function"""

        params = {}
        path = '/functions'
        params['name'] = name
        params['vars'] = vars
        params['trigger'] = trigger
        params['events'] = events
        params['schedule'] = schedule
        params['timeout'] = timeout

        return self.client.call('post', path, {
            'content-type': 'application/json',
        }, params)

    def get(self, function_id):
        """Get Function"""

        params = {}
        path = '/functions/{functionId}'
        path = path.replace('{functionId}', function_id)                

        return self.client.call('get', path, {
            'content-type': 'application/json',
        }, params)

    def update(self, function_id, name, vars=[], trigger='event', events=[], schedule='', timeout=10):
        """Update Function"""

        params = {}
        path = '/functions/{functionId}'
        path = path.replace('{functionId}', function_id)                
        params['name'] = name
        params['vars'] = vars
        params['trigger'] = trigger
        params['events'] = events
        params['schedule'] = schedule
        params['timeout'] = timeout

        return self.client.call('put', path, {
            'content-type': 'application/json',
        }, params)

    def delete(self, function_id):
        """Delete Function"""

        params = {}
        path = '/functions/{functionId}'
        path = path.replace('{functionId}', function_id)                

        return self.client.call('delete', path, {
            'content-type': 'application/json',
        }, params)

    def update_tag(self, function_id, active):
        """Update Function Active Tag"""

        params = {}
        path = '/functions/{functionId}/active'
        path = path.replace('{functionId}', function_id)                
        params['active'] = active

        return self.client.call('patch', path, {
            'content-type': 'application/json',
        }, params)

    def list_executions(self, function_id, search='', limit=25, offset=0, order_type='ASC'):
        """List Executions"""

        params = {}
        path = '/functions/{functionId}/executions'
        path = path.replace('{functionId}', function_id)                
        params['search'] = search
        params['limit'] = limit
        params['offset'] = offset
        params['orderType'] = order_type

        return self.client.call('get', path, {
            'content-type': 'application/json',
        }, params)

    def create_execution(self, function_id, async=1):
        """Create Execution"""

        params = {}
        path = '/functions/{functionId}/executions'
        path = path.replace('{functionId}', function_id)                
        params['async'] = async

        return self.client.call('post', path, {
            'content-type': 'application/json',
        }, params)

    def get_execution(self, function_id, execution_id):
        """Get Execution"""

        params = {}
        path = '/functions/{functionId}/executions/{executionId}'
        path = path.replace('{functionId}', function_id)                
        path = path.replace('{executionId}', execution_id)                

        return self.client.call('get', path, {
            'content-type': 'application/json',
        }, params)

    def list_tags(self, function_id, search='', limit=25, offset=0, order_type='ASC'):
        """List Tags"""

        params = {}
        path = '/functions/{functionId}/tags'
        path = path.replace('{functionId}', function_id)                
        params['search'] = search
        params['limit'] = limit
        params['offset'] = offset
        params['orderType'] = order_type

        return self.client.call('get', path, {
            'content-type': 'application/json',
        }, params)

    def create_tag(self, function_id, env, command, code):
        """Create Tag"""

        params = {}
        path = '/functions/{functionId}/tags'
        path = path.replace('{functionId}', function_id)                
        params['env'] = env
        params['command'] = command
        params['code'] = code

        return self.client.call('post', path, {
            'content-type': 'application/json',
        }, params)

    def get_tag(self, function_id, tag_id):
        """Get Tag"""

        params = {}
        path = '/functions/{functionId}/tags/{tagId}'
        path = path.replace('{functionId}', function_id)                
        path = path.replace('{tagId}', tag_id)                

        return self.client.call('get', path, {
            'content-type': 'application/json',
        }, params)

    def delete_tag(self, function_id, tag_id):
        """Delete Tag"""

        params = {}
        path = '/functions/{functionId}/tags/{tagId}'
        path = path.replace('{functionId}', function_id)                
        path = path.replace('{tagId}', tag_id)                

        return self.client.call('delete', path, {
            'content-type': 'application/json',
        }, params)
