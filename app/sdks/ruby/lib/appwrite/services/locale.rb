module Appwrite
    class Locale < Service

        def get_locale()
            path = '/locale'

            params = {
            }

            return @client.call('get', path, {
            }, params);
        end

        def get_countries()
            path = '/locale/countries'

            params = {
            }

            return @client.call('get', path, {
            }, params);
        end

        def get_countries_e_u()
            path = '/locale/countries/eu'

            params = {
            }

            return @client.call('get', path, {
            }, params);
        end

        def get_countries_phones()
            path = '/locale/countries/phones'

            params = {
            }

            return @client.call('get', path, {
            }, params);
        end


        protected

        private
    end 
end