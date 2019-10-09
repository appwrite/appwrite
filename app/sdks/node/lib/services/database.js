const Service = require('../service.js');

class Database extends Service {

    /**
     * List Collections
     *
     * /docs/references/database/list-collections.md
     *
     * @param string search
     * @param number limit
     * @param number offset
     * @param string orderType
     * @throws Exception
     * @return {}
     */
    async listCollections(search = '', limit = 25, offset = 0, orderType = 'ASC') {
        let path = '/database';
        
        return await this.client.call('get', path, {'content-type': 'application/json'},
            {
                'search': search,
                'limit': limit,
                'offset': offset,
                'orderType': orderType
            });
    }

    /**
     * Create Collection
     *
     * /docs/references/database/create-collection.md
     *
     * @param string name
     * @param array read
     * @param array write
     * @param array rules
     * @throws Exception
     * @return {}
     */
    async createCollection(name, read = [], write = [], rules = []) {
        let path = '/database';
        
        return await this.client.call('post', path, {'content-type': 'application/json'},
            {
                'name': name,
                'read': read,
                'write': write,
                'rules': rules
            });
    }

    /**
     * Get Collection
     *
     * /docs/references/database/get-collection.md
     *
     * @param string collectionId
     * @throws Exception
     * @return {}
     */
    async getCollection(collectionId) {
        let path = '/database/{collectionId}'.replace(new RegExp('{collectionId}', 'g'), collectionId);
        
        return await this.client.call('get', path, {'content-type': 'application/json'},
            {
            });
    }

    /**
     * Update Collection
     *
     * /docs/references/database/update-collection.md
     *
     * @param string collectionId
     * @param string name
     * @param array read
     * @param array write
     * @param array rules
     * @throws Exception
     * @return {}
     */
    async updateCollection(collectionId, name, read = [], write = [], rules = []) {
        let path = '/database/{collectionId}'.replace(new RegExp('{collectionId}', 'g'), collectionId);
        
        return await this.client.call('put', path, {'content-type': 'application/json'},
            {
                'name': name,
                'read': read,
                'write': write,
                'rules': rules
            });
    }

    /**
     * Delete Collection
     *
     * /docs/references/database/delete-collection.md
     *
     * @param string collectionId
     * @throws Exception
     * @return {}
     */
    async deleteCollection(collectionId) {
        let path = '/database/{collectionId}'.replace(new RegExp('{collectionId}', 'g'), collectionId);
        
        return await this.client.call('delete', path, {'content-type': 'application/json'},
            {
            });
    }

    /**
     * List Documents
     *
     * /docs/references/database/list-documents.md
     *
     * @param string collectionId
     * @param array filters
     * @param number offset
     * @param number limit
     * @param string orderField
     * @param string orderType
     * @param string orderCast
     * @param string search
     * @param number first
     * @param number last
     * @throws Exception
     * @return {}
     */
    async listDocuments(collectionId, filters = [], offset = 0, limit = 50, orderField = '$uid', orderType = 'ASC', orderCast = 'string', search = '', first = 0, last = 0) {
        let path = '/database/{collectionId}/documents'.replace(new RegExp('{collectionId}', 'g'), collectionId);
        
        return await this.client.call('get', path, {'content-type': 'application/json'},
            {
                'filters': filters,
                'offset': offset,
                'limit': limit,
                'order-field': orderField,
                'order-type': orderType,
                'order-cast': orderCast,
                'search': search,
                'first': first,
                'last': last
            });
    }

    /**
     * Create Document
     *
     * /docs/references/database/create-document.md
     *
     * @param string collectionId
     * @param string data
     * @param array read
     * @param array write
     * @param string parentDocument
     * @param string parentProperty
     * @param string parentPropertyType
     * @throws Exception
     * @return {}
     */
    async createDocument(collectionId, data, read = [], write = [], parentDocument = '', parentProperty = '', parentPropertyType = 'assign') {
        let path = '/database/{collectionId}/documents'.replace(new RegExp('{collectionId}', 'g'), collectionId);
        
        return await this.client.call('post', path, {'content-type': 'application/json'},
            {
                'data': data,
                'read': read,
                'write': write,
                'parentDocument': parentDocument,
                'parentProperty': parentProperty,
                'parentPropertyType': parentPropertyType
            });
    }

    /**
     * Get Document
     *
     * /docs/references/database/get-document.md
     *
     * @param string collectionId
     * @param string documentId
     * @throws Exception
     * @return {}
     */
    async getDocument(collectionId, documentId) {
        let path = '/database/{collectionId}/documents/{documentId}'.replace(new RegExp('{collectionId}', 'g'), collectionId).replace(new RegExp('{documentId}', 'g'), documentId);
        
        return await this.client.call('get', path, {'content-type': 'application/json'},
            {
            });
    }

    /**
     * Update Document
     *
     * /docs/references/database/update-document.md
     *
     * @param string collectionId
     * @param string documentId
     * @param string data
     * @param array read
     * @param array write
     * @throws Exception
     * @return {}
     */
    async updateDocument(collectionId, documentId, data, read = [], write = []) {
        let path = '/database/{collectionId}/documents/{documentId}'.replace(new RegExp('{collectionId}', 'g'), collectionId).replace(new RegExp('{documentId}', 'g'), documentId);
        
        return await this.client.call('patch', path, {'content-type': 'application/json'},
            {
                'data': data,
                'read': read,
                'write': write
            });
    }

    /**
     * Delete Document
     *
     * /docs/references/database/delete-document.md
     *
     * @param string collectionId
     * @param string documentId
     * @throws Exception
     * @return {}
     */
    async deleteDocument(collectionId, documentId) {
        let path = '/database/{collectionId}/documents/{documentId}'.replace(new RegExp('{collectionId}', 'g'), collectionId).replace(new RegExp('{documentId}', 'g'), documentId);
        
        return await this.client.call('delete', path, {'content-type': 'application/json'},
            {
            });
    }
}

module.exports = Database;