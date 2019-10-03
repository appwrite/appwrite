module Appwrite
    class Auth < Service

        def login(email:, password:, success:, failure:)
            path = '/auth/login'

            params = {
                'email': email, 
                'password': password, 
                'success': success, 
                'failure': failure
            }

            return @client.call('post', path, {
            }, params);
        end

        def logout()
            path = '/auth/logout'

            params = {
            }

            return @client.call('delete', path, {
            }, params);
        end

        def logout_by_session(id:)
            path = '/auth/logout/{id}'
                .gsub('{id}', id)

            params = {
            }

            return @client.call('delete', path, {
            }, params);
        end

        def oauth(provider:, success: '', failure: '')
            path = '/auth/oauth/{provider}'
                .gsub('{provider}', provider)

            params = {
                'success': success, 
                'failure': failure
            }

            return @client.call('get', path, {
            }, params);
        end

        def recovery(email:, reset:)
            path = '/auth/recovery'

            params = {
                'email': email, 
                'reset': reset
            }

            return @client.call('post', path, {
            }, params);
        end

        def recovery_reset(user_id:, token:, password_a:, password_b:)
            path = '/auth/recovery/reset'

            params = {
                'userId': user_id, 
                'token': token, 
                'password-a': password_a, 
                'password-b': password_b
            }

            return @client.call('put', path, {
            }, params);
        end

        def register(email:, password:, confirm:, success: '', failure: '', name: '')
            path = '/auth/register'

            params = {
                'email': email, 
                'password': password, 
                'confirm': confirm, 
                'success': success, 
                'failure': failure, 
                'name': name
            }

            return @client.call('post', path, {
            }, params);
        end

        def confirm(user_id:, token:)
            path = '/auth/register/confirm'

            params = {
                'userId': user_id, 
                'token': token
            }

            return @client.call('post', path, {
            }, params);
        end

        def confirm_resend(confirm:)
            path = '/auth/register/confirm/resend'

            params = {
                'confirm': confirm
            }

            return @client.call('post', path, {
            }, params);
        end


        protected

        private
    end 
end