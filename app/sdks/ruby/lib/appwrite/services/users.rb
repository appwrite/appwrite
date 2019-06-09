module Appwrite
    class Users < Service

        def list_users(search: '', limit: 25, offset: 0, order_type: 'ASC')
            path = '/users'

            params = {
                'search': search, 
                'limit': limit, 
                'offset': offset, 
                'orderType': order_type
            }

            return @client.call('get', path, {
            }, params);
        end

        def create_user(email:, password:, name: '')
            path = '/users'

            params = {
                'email': email, 
                'password': password, 
                'name': name
            }

            return @client.call('post', path, {
            }, params);
        end

        def get_user(user_id:)
            path = '/users/{userId}'
                .gsub('{user_id}', user_id)

            params = {
            }

            return @client.call('get', path, {
            }, params);
        end

        def get_user_logs(user_id:)
            path = '/users/{userId}/logs'
                .gsub('{user_id}', user_id)

            params = {
            }

            return @client.call('get', path, {
            }, params);
        end

        def get_user_prefs(user_id:)
            path = '/users/{userId}/prefs'
                .gsub('{user_id}', user_id)

            params = {
            }

            return @client.call('get', path, {
            }, params);
        end

        def get_user_sessions(user_id:)
            path = '/users/{userId}/sessions'
                .gsub('{user_id}', user_id)

            params = {
            }

            return @client.call('get', path, {
            }, params);
        end

        def delete_user_sessions(user_id:)
            path = '/users/{userId}/sessions'
                .gsub('{user_id}', user_id)

            params = {
            }

            return @client.call('delete', path, {
            }, params);
        end

        def delete_users_session(user_id:, session_id:)
            path = '/users/{userId}/sessions/:session'
                .gsub('{user_id}', user_id)

            params = {
                'sessionId': session_id
            }

            return @client.call('delete', path, {
            }, params);
        end

        def update_user_status(user_id:, status:)
            path = '/users/{userId}/status'
                .gsub('{user_id}', user_id)

            params = {
                'status': status
            }

            return @client.call('patch', path, {
            }, params);
        end


        protected

        private
    end 
end