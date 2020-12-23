from ..service import Service


class Locale(Service):

    def __init__(self, client):
        super(Locale, self).__init__(client)

    def get(self):
        """Get User Locale"""

        params = {}
        path = '/locale'

        return self.client.call('get', path, {
            'content-type': 'application/json',
        }, params)

    def get_continents(self):
        """List Continents"""

        params = {}
        path = '/locale/continents'

        return self.client.call('get', path, {
            'content-type': 'application/json',
        }, params)

    def get_countries(self):
        """List Countries"""

        params = {}
        path = '/locale/countries'

        return self.client.call('get', path, {
            'content-type': 'application/json',
        }, params)

    def get_countries_e_u(self):
        """List EU Countries"""

        params = {}
        path = '/locale/countries/eu'

        return self.client.call('get', path, {
            'content-type': 'application/json',
        }, params)

    def get_countries_phones(self):
        """List Countries Phone Codes"""

        params = {}
        path = '/locale/countries/phones'

        return self.client.call('get', path, {
            'content-type': 'application/json',
        }, params)

    def get_currencies(self):
        """List Currencies"""

        params = {}
        path = '/locale/currencies'

        return self.client.call('get', path, {
            'content-type': 'application/json',
        }, params)
