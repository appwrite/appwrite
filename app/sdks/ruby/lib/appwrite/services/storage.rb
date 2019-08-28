module Appwrite
    class Storage < Service

        def list_files(search: '', limit: 25, offset: 0, order_type: 'ASC')
            path = '/storage/files'

            params = {
                'search': search, 
                'limit': limit, 
                'offset': offset, 
                'orderType': order_type
            }

            return @client.call('get', path, {
            }, params);
        end

        def create_file(files:, read: [], write: [], folder_id: '')
            path = '/storage/files'

            params = {
                'files': files, 
                'read': read, 
                'write': write, 
                'folderId': folder_id
            }

            return @client.call('post', path, {
            }, params);
        end

        def get_file(file_id:)
            path = '/storage/files/{fileId}'
                .gsub('{file_id}', file_id)

            params = {
            }

            return @client.call('get', path, {
            }, params);
        end

        def update_file(file_id:, read: [], write: [], folder_id: '')
            path = '/storage/files/{fileId}'
                .gsub('{file_id}', file_id)

            params = {
                'read': read, 
                'write': write, 
                'folderId': folder_id
            }

            return @client.call('put', path, {
            }, params);
        end

        def delete_file(file_id:)
            path = '/storage/files/{fileId}'
                .gsub('{file_id}', file_id)

            params = {
            }

            return @client.call('delete', path, {
            }, params);
        end

        def get_file_download(file_id:)
            path = '/storage/files/{fileId}/download'
                .gsub('{file_id}', file_id)

            params = {
            }

            return @client.call('get', path, {
            }, params);
        end

        def get_file_preview(file_id:, width: 0, height: 0, quality: 100, background: '', output: '')
            path = '/storage/files/{fileId}/preview'
                .gsub('{file_id}', file_id)

            params = {
                'width': width, 
                'height': height, 
                'quality': quality, 
                'background': background, 
                'output': output
            }

            return @client.call('get', path, {
            }, params);
        end

        def get_file_view(file_id:, as: '')
            path = '/storage/files/{fileId}/view'
                .gsub('{file_id}', file_id)

            params = {
                'as': as
            }

            return @client.call('get', path, {
            }, params);
        end


        protected

        private
    end 
end