from ..service import Service


class Health(Service):

    def __init__(self, client):
        super(Health, self).__init__(client)

    def get(self):
        """Check API HTTP Health"""

        params = {}
        path = '/health'

        return self.client.call('get', path, {
            'content-type': 'application/json',
        }, params)

    def get_cache(self):
        """Check Cache Health"""

        params = {}
        path = '/health/cache'

        return self.client.call('get', path, {
            'content-type': 'application/json',
        }, params)

    def get_d_b(self):
        """Check DB Health"""

        params = {}
        path = '/health/db'

        return self.client.call('get', path, {
            'content-type': 'application/json',
        }, params)

    def get_queue_certificates(self):
        """Check the number of pending certificate messages"""

        params = {}
        path = '/health/queue/certificates'

        return self.client.call('get', path, {
            'content-type': 'application/json',
        }, params)

    def get_queue_logs(self):
        """Check the number of pending log messages"""

        params = {}
        path = '/health/queue/logs'

        return self.client.call('get', path, {
            'content-type': 'application/json',
        }, params)

    def get_queue_tasks(self):
        """Check the number of pending task messages"""

        params = {}
        path = '/health/queue/tasks'

        return self.client.call('get', path, {
            'content-type': 'application/json',
        }, params)

    def get_queue_usage(self):
        """Check the number of pending usage messages"""

        params = {}
        path = '/health/queue/usage'

        return self.client.call('get', path, {
            'content-type': 'application/json',
        }, params)

    def get_queue_webhooks(self):
        """Check number of pending webhook messages"""

        params = {}
        path = '/health/queue/webhooks'

        return self.client.call('get', path, {
            'content-type': 'application/json',
        }, params)

    def get_storage_anti_virus(self):
        """Check Anti virus Health"""

        params = {}
        path = '/health/storage/anti-virus'

        return self.client.call('get', path, {
            'content-type': 'application/json',
        }, params)

    def get_storage_local(self):
        """Check File System Health"""

        params = {}
        path = '/health/storage/local'

        return self.client.call('get', path, {
            'content-type': 'application/json',
        }, params)

    def get_time(self):
        """Check Time Health"""

        params = {}
        path = '/health/time'

        return self.client.call('get', path, {
            'content-type': 'application/json',
        }, params)
