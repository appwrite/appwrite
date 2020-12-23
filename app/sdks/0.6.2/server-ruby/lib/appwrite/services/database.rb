module Appwrite
    class Database < Service

        def list_collections(search: '', limit: 25, offset: 0, order_type: 'ASC')
            path = '/database/collections'

            params = {
                'search': search, 
                'limit': limit, 
                'offset': offset, 
                'orderType': order_type
            }

            return @client.call('get', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def create_collection(name:, read:, write:, rules:)
            path = '/database/collections'

            params = {
                'name': name, 
                'read': read, 
                'write': write, 
                'rules': rules
            }

            return @client.call('post', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def get_collection(collection_id:)
            path = '/database/collections/{collectionId}'
                .gsub('{collectionId}', collection_id)

            params = {
            }

            return @client.call('get', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def update_collection(collection_id:, name:, read:, write:, rules: [])
            path = '/database/collections/{collectionId}'
                .gsub('{collectionId}', collection_id)

            params = {
                'name': name, 
                'read': read, 
                'write': write, 
                'rules': rules
            }

            return @client.call('put', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def delete_collection(collection_id:)
            path = '/database/collections/{collectionId}'
                .gsub('{collectionId}', collection_id)

            params = {
            }

            return @client.call('delete', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def list_documents(collection_id:, filters: [], offset: 0, limit: 50, order_field: '$id', order_type: 'ASC', order_cast: 'string', search: '')
            path = '/database/collections/{collectionId}/documents'
                .gsub('{collectionId}', collection_id)

            params = {
                'filters': filters, 
                'offset': offset, 
                'limit': limit, 
                'orderField': order_field, 
                'orderType': order_type, 
                'orderCast': order_cast, 
                'search': search
            }

            return @client.call('get', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def create_document(collection_id:, data:, read:, write:, parent_document: '', parent_property: '', parent_property_type: 'assign')
            path = '/database/collections/{collectionId}/documents'
                .gsub('{collectionId}', collection_id)

            params = {
                'data': data, 
                'read': read, 
                'write': write, 
                'parentDocument': parent_document, 
                'parentProperty': parent_property, 
                'parentPropertyType': parent_property_type
            }

            return @client.call('post', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def get_document(collection_id:, document_id:)
            path = '/database/collections/{collectionId}/documents/{documentId}'
                .gsub('{collectionId}', collection_id)
                .gsub('{documentId}', document_id)

            params = {
            }

            return @client.call('get', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def update_document(collection_id:, document_id:, data:, read:, write:)
            path = '/database/collections/{collectionId}/documents/{documentId}'
                .gsub('{collectionId}', collection_id)
                .gsub('{documentId}', document_id)

            params = {
                'data': data, 
                'read': read, 
                'write': write
            }

            return @client.call('patch', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def delete_document(collection_id:, document_id:)
            path = '/database/collections/{collectionId}/documents/{documentId}'
                .gsub('{collectionId}', collection_id)
                .gsub('{documentId}', document_id)

            params = {
            }

            return @client.call('delete', path, {
                'content-type' => 'application/json',
            }, params);
        end


        protected

        private
    end 
end