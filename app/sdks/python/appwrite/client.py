import requests


class Client:
    def __init__(self):
        self._self_signed = False
        self._endpoint = 'https://appwrite.io/v1'
        self._global_headers = {
            'content-type': '',
            'x-sdk-version': 'appwrite:python:0.0.1',
        }

    def set_self_signed(self, status=True):
        self._self_signed = status
        return self

    def set_endpoint(self, endpoint):
        self._endpoint = endpoint
        return self

    def add_header(self, key, value):
        self._global_headers[key.lower()] = value.lower()
        return self

    def set_project(self, value):
        """Your Appwrite project ID"""

        self._global_headers['x-appwrite-project'] = value.lower()
        return self

    def set_key(self, value):
        """Your Appwrite project secret key"""

        self._global_headers['x-appwrite-key'] = value.lower()
        return self

    def set_locale(self, value):
        self._global_headers['x-appwrite-locale'] = value.lower()
        return self

    def set_mode(self, value):
        self._global_headers['x-appwrite-mode'] = value.lower()
        return self

    def call(self, method, path='', headers=None, params=None):
        if headers is None:
            headers = {}

        if params is None:
            params = {}

        data = {}
        json = {}
        headers = {**self._global_headers, **headers}

        if method != 'get':
            data = params
            params = {}

        if headers['content-type'] == 'application/json':
            json = data
            data = {}

        response = getattr(requests, method)(  # call method dynamically https://stackoverflow.com/a/4246075/2299554
            url=self._endpoint + path,
            params=params,
            data=data,
            json=json,
            headers=headers,
            verify=self._self_signed,
        )

        return response
