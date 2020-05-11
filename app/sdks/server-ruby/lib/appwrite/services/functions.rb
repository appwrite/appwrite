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

        def create(name:, vars: [], trigger: 'event', events: [], schedule: '', timeout: 10)
            path = '/functions'

            params = {
                'name': name, 
                'vars': vars, 
                'trigger': trigger, 
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
                .gsub('{function_id}', function_id)

            params = {
            }

            return @client.call('get', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def update(function_id:, name:, vars: [], trigger: 'event', events: [], schedule: '', timeout: 10)
            path = '/functions/{functionId}'
                .gsub('{function_id}', function_id)

            params = {
                'name': name, 
                'vars': vars, 
                'trigger': trigger, 
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
                .gsub('{function_id}', function_id)

            params = {
            }

            return @client.call('delete', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def list_executions(function_id:, search: '', limit: 25, offset: 0, order_type: 'ASC')
            path = '/functions/{functionId}/executions'
                .gsub('{function_id}', function_id)

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

        def create_execution(function_id:, async: 1)
            path = '/functions/{functionId}/executions'
                .gsub('{function_id}', function_id)

            params = {
                'async': async
            }

            return @client.call('post', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def get_execution(function_id:, execution_id:)
            path = '/functions/{functionId}/executions/{executionId}'
                .gsub('{function_id}', function_id)
                .gsub('{execution_id}', execution_id)

            params = {
            }

            return @client.call('get', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def update_tag(function_id:, tag:)
            path = '/functions/{functionId}/tag'
                .gsub('{function_id}', function_id)

            params = {
                'tag': tag
            }

            return @client.call('patch', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def list_tags(function_id:, search: '', limit: 25, offset: 0, order_type: 'ASC')
            path = '/functions/{functionId}/tags'
                .gsub('{function_id}', function_id)

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

        def create_tag(function_id:, env:, command:, code:)
            path = '/functions/{functionId}/tags'
                .gsub('{function_id}', function_id)

            params = {
                'env': env, 
                'command': command, 
                'code': code
            }

            return @client.call('post', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def get_tag(function_id:, tag_id:)
            path = '/functions/{functionId}/tags/{tagId}'
                .gsub('{function_id}', function_id)
                .gsub('{tag_id}', tag_id)

            params = {
            }

            return @client.call('get', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def delete_tag(function_id:, tag_id:)
            path = '/functions/{functionId}/tags/{tagId}'
                .gsub('{function_id}', function_id)
                .gsub('{tag_id}', tag_id)

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