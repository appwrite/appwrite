module Appwrite
    class Account < Service

        def get()
            path = '/account'

            params = {
            }

            return @client.call('get', path, {
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

        def update_name(name:)
            path = '/account/name'

            params = {
                'name': name
            }

            return @client.call('patch', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def update_password(password:, old_password:)
            path = '/account/password'

            params = {
                'password': password, 
                'old-password': old_password
            }

            return @client.call('patch', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def get_prefs()
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

        def get_security()
            path = '/account/security'

            params = {
            }

            return @client.call('get', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def get_sessions()
            path = '/account/sessions'

            params = {
            }

            return @client.call('get', path, {
                'content-type' => 'application/json',
            }, params);
        end


        protected

        private
    end 
end