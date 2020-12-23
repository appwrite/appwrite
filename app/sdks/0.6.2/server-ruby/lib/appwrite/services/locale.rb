module Appwrite
    class Locale < Service

        def get()
            path = '/locale'

            params = {
            }

            return @client.call('get', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def get_continents()
            path = '/locale/continents'

            params = {
            }

            return @client.call('get', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def get_countries()
            path = '/locale/countries'

            params = {
            }

            return @client.call('get', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def get_countries_e_u()
            path = '/locale/countries/eu'

            params = {
            }

            return @client.call('get', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def get_countries_phones()
            path = '/locale/countries/phones'

            params = {
            }

            return @client.call('get', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def get_currencies()
            path = '/locale/currencies'

            params = {
            }

            return @client.call('get', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def get_languages()
            path = '/locale/languages'

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