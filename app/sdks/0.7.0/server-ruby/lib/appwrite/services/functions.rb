module Appwrite
    class Functions < Service

        def list(search: '', limit: 25, offset: 0, order_type: 'ASC')
            path = '/functions'

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

        def create(name:, env:, vars: [], events: [], schedule: '', timeout: 15)
            path = '/functions'

            params = {
                'name': name, 
                'env': env, 
                'vars': vars, 
                'events': events, 
                'schedule': schedule, 
                'timeout': timeout
            }

            return @client.call('post', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def get(function_id:)
            path = '/functions/{functionId}'
                .gsub('{functionId}', function_id)

            params = {
            }

            return @client.call('get', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def update(function_id:, name:, vars: [], events: [], schedule: '', timeout: 15)
            path = '/functions/{functionId}'
                .gsub('{functionId}', function_id)

            params = {
                'name': name, 
                'vars': vars, 
                'events': events, 
                'schedule': schedule, 
                'timeout': timeout
            }

            return @client.call('put', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def delete(function_id:)
            path = '/functions/{functionId}'
                .gsub('{functionId}', function_id)

            params = {
            }

            return @client.call('delete', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def list_executions(function_id:, search: '', limit: 25, offset: 0, order_type: 'ASC')
            path = '/functions/{functionId}/executions'
                .gsub('{functionId}', function_id)

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

        def create_execution(function_id:)
            path = '/functions/{functionId}/executions'
                .gsub('{functionId}', function_id)

            params = {
            }

            return @client.call('post', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def get_execution(function_id:, execution_id:)
            path = '/functions/{functionId}/executions/{executionId}'
                .gsub('{functionId}', function_id)
                .gsub('{executionId}', execution_id)

            params = {
            }

            return @client.call('get', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def update_tag(function_id:, tag:)
            path = '/functions/{functionId}/tag'
                .gsub('{functionId}', function_id)

            params = {
                'tag': tag
            }

            return @client.call('patch', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def list_tags(function_id:, search: '', limit: 25, offset: 0, order_type: 'ASC')
            path = '/functions/{functionId}/tags'
                .gsub('{functionId}', function_id)

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

        def create_tag(function_id:, command:, code:)
            path = '/functions/{functionId}/tags'
                .gsub('{functionId}', function_id)

            params = {
                'command': command, 
                'code': code
            }

            return @client.call('post', path, {
                'content-type' => 'multipart/form-data',
            }, params);
        end

        def get_tag(function_id:, tag_id:)
            path = '/functions/{functionId}/tags/{tagId}'
                .gsub('{functionId}', function_id)
                .gsub('{tagId}', tag_id)

            params = {
            }

            return @client.call('get', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def delete_tag(function_id:, tag_id:)
            path = '/functions/{functionId}/tags/{tagId}'
                .gsub('{functionId}', function_id)
                .gsub('{tagId}', tag_id)

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