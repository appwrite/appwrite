module Appwrite
    class Auth < Service

        def login(email:, password:, success: '', failure: '')
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

        def oauth_callback(project_id:, provider:, code:, state: '')
            path = '/auth/oauth/callback/{provider}/{projectId}'
                .gsub('{project_id}', project_id)
                .gsub('{provider}', provider)

            params = {
                'code': code, 
                'state': state
            }

            return @client.call('get', path, {
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

        def recovery(email:, redirect:)
            path = '/auth/recovery'

            params = {
                'email': email, 
                'redirect': redirect
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

        def register(email:, password:, redirect:, name: '', success: '', failure: '')
            path = '/auth/register'

            params = {
                'email': email, 
                'password': password, 
                'name': name, 
                'redirect': redirect, 
                'success': success, 
                'failure': failure
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

        def confirm_resend(redirect:)
            path = '/auth/register/confirm/resend'

            params = {
                'redirect': redirect
            }

            return @client.call('post', path, {
            }, params);
        end


        protected

        private
    end 
end