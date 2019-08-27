from ..service import Service


class Storage(Service):

    def list_files(self, search='', limit=25, offset=0, order_type='ASC'):
        """List Files"""

        params = {}
        path = '/storage/files'
        params['search'] = search
        params['limit'] = limit
        params['offset'] = offset
        params['orderType'] = order_type

        return self.client.call('get', path, {
        }, params)

    def create_file(self, files, readstring(4) ""[]""
=[], writestring(4) ""[]""
=[], folder_id=''):
        """Create File"""

        params = {}
        path = '/storage/files'
        params['files'] = files
        params['read'] = read
        params['write'] = write
        params['folderId'] = folder_id

        return self.client.call('post', path, {
            'content-type': 'Array',
        }, params)

    def get_file(self, file_id):
        """Get File"""

        params = {}
        path = '/storage/files/{fileId}'
        path.replace('{fileId}', file_id)                

        return self.client.call('get', path, {
        }, params)

    def delete_file(self, file_id):
        """Delete File"""

        params = {}
        path = '/storage/files/{fileId}'
        path.replace('{fileId}', file_id)                

        return self.client.call('delete', path, {
        }, params)

    def get_file_download(self, file_id):
        """Get File for Download"""

        params = {}
        path = '/storage/files/{fileId}/download'
        path.replace('{fileId}', file_id)                

        return self.client.call('get', path, {
        }, params)

    def get_file_preview(self, file_id, width=0, height=0, quality=100, background='', output=''):
        """Get File Preview"""

        params = {}
        path = '/storage/files/{fileId}/preview'
        path.replace('{fileId}', file_id)                
        params['width'] = width
        params['height'] = height
        params['quality'] = quality
        params['background'] = background
        params['output'] = output

        return self.client.call('get', path, {
        }, params)

    def get_file_view(self, file_id, as=''):
        """Get File for View"""

        params = {}
        path = '/storage/files/{fileId}/view'
        path.replace('{fileId}', file_id)                
        params['as'] = as

        return self.client.call('get', path, {
        }, params)
