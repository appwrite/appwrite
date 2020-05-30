import { Service } from "../service.ts";
import { DocumentData } from '../client.ts'

export class Database extends Service {

    /**
     * List Collections
     *
     * Get a list of all the user collections. You can use the query params to
     * filter your results. On admin mode, this endpoint will return a list of all
     * of the project collections. [Learn more about different API
     * modes](/docs/admin).
     *
     * @param string search
     * @param number limit
     * @param number offset
     * @param string orderType
     * @throws Exception
     * @return Promise<string>
     */
    async listCollections(search: string = '', limit: number = 25, offset: number = 0, orderType: string = 'ASC'): Promise<string> {
        let path = '/database/collections';
        
        return await this.client.call('get', path, {
                    'content-type': 'application/json',
               },
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
     * Create a new Collection.
     *
     * @param string name
     * @param Array<string> read
     * @param Array<string> write
     * @param Array<string> rules
     * @throws Exception
     * @return Promise<string>
     */
    async createCollection(name: string, read: Array<string>, write: Array<string>, rules: Array<string>): Promise<string> {
        let path = '/database/collections';
        
        return await this.client.call('post', path, {
                    'content-type': 'application/json',
               },
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
     * Get collection by its unique ID. This endpoint response returns a JSON
     * object with the collection metadata.
     *
     * @param string collectionId
     * @throws Exception
     * @return Promise<string>
     */
    async getCollection(collectionId: string): Promise<string> {
        let path = '/database/collections/{collectionId}'.replace(new RegExp('{collectionId}', 'g'), collectionId);
        
        return await this.client.call('get', path, {
                    'content-type': 'application/json',
               },
               {
            });
    }

    /**
     * Update Collection
     *
     * Update collection by its unique ID.
     *
     * @param string collectionId
     * @param string name
     * @param Array<string> read
     * @param Array<string> write
     * @param Array<string> rules
     * @throws Exception
     * @return Promise<string>
     */
    async updateCollection(collectionId: string, name: string, read: Array<string>, write: Array<string>, rules: Array<string> = []): Promise<string> {
        let path = '/database/collections/{collectionId}'.replace(new RegExp('{collectionId}', 'g'), collectionId);
        
        return await this.client.call('put', path, {
                    'content-type': 'application/json',
               },
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
     * Delete a collection by its unique ID. Only users with write permissions
     * have access to delete this resource.
     *
     * @param string collectionId
     * @throws Exception
     * @return Promise<string>
     */
    async deleteCollection(collectionId: string): Promise<string> {
        let path = '/database/collections/{collectionId}'.replace(new RegExp('{collectionId}', 'g'), collectionId);
        
        return await this.client.call('delete', path, {
                    'content-type': 'application/json',
               },
               {
            });
    }

    /**
     * List Documents
     *
     * Get a list of all the user documents. You can use the query params to
     * filter your results. On admin mode, this endpoint will return a list of all
     * of the project documents. [Learn more about different API
     * modes](/docs/admin).
     *
     * @param string collectionId
     * @param Array<string> filters
     * @param number offset
     * @param number limit
     * @param string orderField
     * @param string orderType
     * @param string orderCast
     * @param string search
     * @param number first
     * @param number last
     * @throws Exception
     * @return Promise<string>
     */
    async listDocuments(collectionId: string, filters: Array<string> = [], offset: number = 0, limit: number = 50, orderField: string = '$id', orderType: string = 'ASC', orderCast: string = 'string', search: string = '', first: number = 0, last: number = 0): Promise<string> {
        let path = '/database/collections/{collectionId}/documents'.replace(new RegExp('{collectionId}', 'g'), collectionId);
        
        return await this.client.call('get', path, {
                    'content-type': 'application/json',
               },
               {
                'filters': filters,
                'offset': offset,
                'limit': limit,
                'orderField': orderField,
                'orderType': orderType,
                'orderCast': orderCast,
                'search': search,
                'first': first,
                'last': last
            });
    }

    /**
     * Create Document
     *
     * Create a new Document.
     *
     * @param string collectionId
     * @param DocumentData data
     * @param Array<string> read
     * @param Array<string> write
     * @param string parentDocument
     * @param string parentProperty
     * @param string parentPropertyType
     * @throws Exception
     * @return Promise<string>
     */
    async createDocument(collectionId: string, data: DocumentData, read: Array<string>, write: Array<string>, parentDocument: string = '', parentProperty: string = '', parentPropertyType: string = 'assign'): Promise<string> {
        let path = '/database/collections/{collectionId}/documents'.replace(new RegExp('{collectionId}', 'g'), collectionId);
        
        return await this.client.call('post', path, {
                    'content-type': 'application/json',
               },
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
     * Get document by its unique ID. This endpoint response returns a JSON object
     * with the document data.
     *
     * @param string collectionId
     * @param string documentId
     * @throws Exception
     * @return Promise<string>
     */
    async getDocument(collectionId: string, documentId: string): Promise<string> {
        let path = '/database/collections/{collectionId}/documents/{documentId}'.replace(new RegExp('{collectionId}', 'g'), collectionId).replace(new RegExp('{documentId}', 'g'), documentId);
        
        return await this.client.call('get', path, {
                    'content-type': 'application/json',
               },
               {
            });
    }

    /**
     * Update Document
     *
     * @param string collectionId
     * @param string documentId
     * @param DocumentData data
     * @param Array<string> read
     * @param Array<string> write
     * @throws Exception
     * @return Promise<string>
     */
    async updateDocument(collectionId: string, documentId: string, data: DocumentData, read: Array<string>, write: Array<string>): Promise<string> {
        let path = '/database/collections/{collectionId}/documents/{documentId}'.replace(new RegExp('{collectionId}', 'g'), collectionId).replace(new RegExp('{documentId}', 'g'), documentId);
        
        return await this.client.call('patch', path, {
                    'content-type': 'application/json',
               },
               {
                'data': data,
                'read': read,
                'write': write
            });
    }

    /**
     * Delete Document
     *
     * Delete document by its unique ID. This endpoint deletes only the parent
     * documents, his attributes and relations to other documents. Child documents
     * **will not** be deleted.
     *
     * @param string collectionId
     * @param string documentId
     * @throws Exception
     * @return Promise<string>
     */
    async deleteDocument(collectionId: string, documentId: string): Promise<string> {
        let path = '/database/collections/{collectionId}/documents/{documentId}'.replace(new RegExp('{collectionId}', 'g'), collectionId).replace(new RegExp('{documentId}', 'g'), documentId);
        
        return await this.client.call('delete', path, {
                    'content-type': 'application/json',
               },
               {
            });
    }

    /**
     * Get Collection Logs
     *
     * @param string collectionId
     * @throws Exception
     * @return Promise<string>
     */
    async getCollectionLogs(collectionId: string): Promise<string> {
        let path = '/database/collections/{collectionId}/logs'.replace(new RegExp('{collectionId}', 'g'), collectionId);
        
        return await this.client.call('get', path, {
                    'content-type': 'application/json',
               },
               {
            });
    }
}