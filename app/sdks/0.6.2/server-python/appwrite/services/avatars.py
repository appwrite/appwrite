from ..service import Service


class Avatars(Service):

    def __init__(self, client):
        super(Avatars, self).__init__(client)

    def get_browser(self, code, width=100, height=100, quality=100):
        """Get Browser Icon"""

        params = {}
        path = '/avatars/browsers/{code}'
        path = path.replace('{code}', code)                
        params['width'] = width
        params['height'] = height
        params['quality'] = quality

        return self.client.call('get', path, {
            'content-type': 'application/json',
        }, params)

    def get_credit_card(self, code, width=100, height=100, quality=100):
        """Get Credit Card Icon"""

        params = {}
        path = '/avatars/credit-cards/{code}'
        path = path.replace('{code}', code)                
        params['width'] = width
        params['height'] = height
        params['quality'] = quality

        return self.client.call('get', path, {
            'content-type': 'application/json',
        }, params)

    def get_favicon(self, url):
        """Get Favicon"""

        params = {}
        path = '/avatars/favicon'
        params['url'] = url

        return self.client.call('get', path, {
            'content-type': 'application/json',
        }, params)

    def get_flag(self, code, width=100, height=100, quality=100):
        """Get Country Flag"""

        params = {}
        path = '/avatars/flags/{code}'
        path = path.replace('{code}', code)                
        params['width'] = width
        params['height'] = height
        params['quality'] = quality

        return self.client.call('get', path, {
            'content-type': 'application/json',
        }, params)

    def get_image(self, url, width=400, height=400):
        """Get Image from URL"""

        params = {}
        path = '/avatars/image'
        params['url'] = url
        params['width'] = width
        params['height'] = height

        return self.client.call('get', path, {
            'content-type': 'application/json',
        }, params)

    def get_initials(self, name='', width=500, height=500, color='', background=''):
        """Get User Initials"""

        params = {}
        path = '/avatars/initials'
        params['name'] = name
        params['width'] = width
        params['height'] = height
        params['color'] = color
        params['background'] = background

        return self.client.call('get', path, {
            'content-type': 'application/json',
        }, params)

    def get_q_r(self, text, size=400, margin=1, download=False):
        """Get QR Code"""

        params = {}
        path = '/avatars/qr'
        params['text'] = text
        params['size'] = size
        params['margin'] = margin
        params['download'] = download

        return self.client.call('get', path, {
            'content-type': 'application/json',
        }, params)
