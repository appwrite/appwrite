module Appwrite
    class Account < Service

        def get_account()
            path = '/account'

            params = {
            }

            return @client.call('get', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def create_account(email:, password:, name: '')
            path = '/account'

            params = {
                'email': email, 
                'password': password, 
                'name': name
            }

            return @client.call('post', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def delete()
            path = '/account'

            params = {
            }

            return @client.call('delete', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def update_email(email:, password:)
            path = '/account/email'

            params = {
                'email': email, 
                'password': password
            }

            return @client.call('patch', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def get_account_logs()
            path = '/account/logs'

            params = {
            }

            return @client.call('get', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def update_account_name(name:)
            path = '/account/name'

            params = {
                'name': name
            }

            return @client.call('patch', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def update_account_password(password:, old_password:)
            path = '/account/password'

            params = {
                'password': password, 
                'old-password': old_password
            }

            return @client.call('patch', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def get_account_prefs()
            path = '/account/prefs'

            params = {
            }

            return @client.call('get', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def update_prefs(prefs:)
            path = '/account/prefs'

            params = {
                'prefs': prefs
            }

            return @client.call('patch', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def create_account_recovery(email:, url:)
            path = '/account/recovery'

            params = {
                'email': email, 
                'url': url
            }

            return @client.call('post', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def update_account_recovery(user_id:, secret:, password_a:, password_b:)
            path = '/account/recovery'

            params = {
                'userId': user_id, 
                'secret': secret, 
                'password-a': password_a, 
                'password-b': password_b
            }

            return @client.call('put', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def get_account_sessions()
            path = '/account/sessions'

            params = {
            }

            return @client.call('get', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def create_account_session(email:, password:)
            path = '/account/sessions'

            params = {
                'email': email, 
                'password': password
            }

            return @client.call('post', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def delete_account_sessions()
            path = '/account/sessions'

            params = {
            }

            return @client.call('delete', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def delete_account_current_session()
            path = '/account/sessions/current'

            params = {
            }

            return @client.call('delete', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def create_account_session_o_auth(provider:, success:, failure:)
            path = '/account/sessions/oauth/{provider}'
                .gsub('{provider}', provider)

            params = {
                'success': success, 
                'failure': failure
            }

            return @client.call('get', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def delete_account_session(id:)
            path = '/account/sessions/{id}'
                .gsub('{id}', id)

            params = {
            }

            return @client.call('delete', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def create_account_verification(url:)
            path = '/account/verification'

            params = {
                'url': url
            }

            return @client.call('post', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def update_account_verification(user_id:, secret:, password_b:)
            path = '/account/verification'

            params = {
                'userId': user_id, 
                'secret': secret, 
                'password-b': password_b
            }

            return @client.call('put', path, {
                'content-type' => 'application/json',
            }, params);
        end


        protected

        private
    end 
end