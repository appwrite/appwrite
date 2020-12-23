from ..service import Service


class Database(Service):

    def __init__(self, client):
        super(Database, self).__init__(client)

    def list_collections(self, search='', limit=25, offset=0, order_type='ASC'):
        """List Collections"""

        params = {}
        path = '/database/collections'
        params['search'] = search
        params['limit'] = limit
        params['offset'] = offset
        params['orderType'] = order_type

        return self.client.call('get', path, {
            'content-type': 'application/json',
        }, params)

    def create_collection(self, name, read, write, rules):
        """Create Collection"""

        params = {}
        path = '/database/collections'
        params['name'] = name
        params['read'] = read
        params['write'] = write
        params['rules'] = rules

        return self.client.call('post', path, {
            'content-type': 'application/json',
        }, params)

    def get_collection(self, collection_id):
        """Get Collection"""

        params = {}
        path = '/database/collections/{collectionId}'
        path = path.replace('{collectionId}', collection_id)                

        return self.client.call('get', path, {
            'content-type': 'application/json',
        }, params)

    def update_collection(self, collection_id, name, read, write, rules=[]):
        """Update Collection"""

        params = {}
        path = '/database/collections/{collectionId}'
        path = path.replace('{collectionId}', collection_id)                
        params['name'] = name
        params['read'] = read
        params['write'] = write
        params['rules'] = rules

        return self.client.call('put', path, {
            'content-type': 'application/json',
        }, params)

    def delete_collection(self, collection_id):
        """Delete Collection"""

        params = {}
        path = '/database/collections/{collectionId}'
        path = path.replace('{collectionId}', collection_id)                

        return self.client.call('delete', path, {
            'content-type': 'application/json',
        }, params)

    def list_documents(self, collection_id, filters=[], limit=25, offset=0, order_field='', order_type='ASC', order_cast='string', search=''):
        """List Documents"""

        params = {}
        path = '/database/collections/{collectionId}/documents'
        path = path.replace('{collectionId}', collection_id)                
        params['filters'] = filters
        params['limit'] = limit
        params['offset'] = offset
        params['orderField'] = order_field
        params['orderType'] = order_type
        params['orderCast'] = order_cast
        params['search'] = search

        return self.client.call('get', path, {
            'content-type': 'application/json',
        }, params)

    def create_document(self, collection_id, data, read, write, parent_document='', parent_property='', parent_property_type='assign'):
        """Create Document"""

        params = {}
        path = '/database/collections/{collectionId}/documents'
        path = path.replace('{collectionId}', collection_id)                
        params['data'] = data
        params['read'] = read
        params['write'] = write
        params['parentDocument'] = parent_document
        params['parentProperty'] = parent_property
        params['parentPropertyType'] = parent_property_type

        return self.client.call('post', path, {
            'content-type': 'application/json',
        }, params)

    def get_document(self, collection_id, document_id):
        """Get Document"""

        params = {}
        path = '/database/collections/{collectionId}/documents/{documentId}'
        path = path.replace('{collectionId}', collection_id)                
        path = path.replace('{documentId}', document_id)                

        return self.client.call('get', path, {
            'content-type': 'application/json',
        }, params)

    def update_document(self, collection_id, document_id, data, read, write):
        """Update Document"""

        params = {}
        path = '/database/collections/{collectionId}/documents/{documentId}'
        path = path.replace('{collectionId}', collection_id)                
        path = path.replace('{documentId}', document_id)                
        params['data'] = data
        params['read'] = read
        params['write'] = write

        return self.client.call('patch', path, {
            'content-type': 'application/json',
        }, params)

    def delete_document(self, collection_id, document_id):
        """Delete Document"""

        params = {}
        path = '/database/collections/{collectionId}/documents/{documentId}'
        path = path.replace('{collectionId}', collection_id)                
        path = path.replace('{documentId}', document_id)                

        return self.client.call('delete', path, {
            'content-type': 'application/json',
        }, params)
