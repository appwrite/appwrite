module Appwrite
    class Users < Service

        def list(search: '', limit: 25, offset: 0, order_type: 'ASC')
            path = '/users'

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

        def create(email:, password:, name: '')
            path = '/users'

            params = {
                'email': email, 
                'password': password, 
                'name': name
            }

            return @client.call('post', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def get(user_id:)
            path = '/users/{userId}'
                .gsub('{user_id}', user_id)

            params = {
            }

            return @client.call('get', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def get_logs(user_id:)
            path = '/users/{userId}/logs'
                .gsub('{user_id}', user_id)

            params = {
            }

            return @client.call('get', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def get_prefs(user_id:)
            path = '/users/{userId}/prefs'
                .gsub('{user_id}', user_id)

            params = {
            }

            return @client.call('get', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def update_prefs(user_id:, prefs:)
            path = '/users/{userId}/prefs'
                .gsub('{user_id}', user_id)

            params = {
                'prefs': prefs
            }

            return @client.call('patch', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def get_sessions(user_id:)
            path = '/users/{userId}/sessions'
                .gsub('{user_id}', user_id)

            params = {
            }

            return @client.call('get', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def delete_sessions(user_id:)
            path = '/users/{userId}/sessions'
                .gsub('{user_id}', user_id)

            params = {
            }

            return @client.call('delete', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def delete_session(user_id:, session_id:)
            path = '/users/{userId}/sessions/{sessionId}'
                .gsub('{user_id}', user_id)
                .gsub('{session_id}', session_id)

            params = {
            }

            return @client.call('delete', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def update_status(user_id:, status:)
            path = '/users/{userId}/status'
                .gsub('{user_id}', user_id)

            params = {
                'status': status
            }

            return @client.call('patch', path, {
                'content-type' => 'application/json',
            }, params);
        end


        protected

        private
    end 
end