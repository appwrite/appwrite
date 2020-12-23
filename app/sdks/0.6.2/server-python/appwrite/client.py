import io
import requests

class Client:
    def __init__(self):
        self._self_signed = False
        self._endpoint = 'https://appwrite.io/v1'
        self._global_headers = {
            'content-type': '',
            'x-sdk-version': 'appwrite:python:0.0.6',
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
        """Your project ID"""

        self._global_headers['x-appwrite-project'] = value.lower()
        return self

    def set_key(self, value):
        """Your secret API key"""

        self._global_headers['x-appwrite-key'] = value.lower()
        return self

    def set_locale(self, value):
        self._global_headers['x-appwrite-locale'] = value.lower()
        return self

    def call(self, method, path='', headers=None, params=None):
        if headers is None:
            headers = {}

        if params is None:
            params = {}

        data = {}
        json = {}
        files = {}
        
        headers = {**self._global_headers, **headers}

        if method != 'get':
            data = params
            params = {}

        if headers['content-type'].startswith('application/json'):
            json = data
            data = {}

        if headers['content-type'].startswith('multipart/form-data'):
            del headers['content-type']
            
            for key in data.copy():
                if isinstance(data[key], io.BufferedIOBase):
                    files[key] = data[key]
                    del data[key]

        response = requests.request(  # call method dynamically https://stackoverflow.com/a/4246075/2299554
            method=method,
            url=self._endpoint + path,
            params=self.flatten(params),
            data=self.flatten(data),
            json=json,
            files=files,
            headers=headers,
            verify=self._self_signed,
        )

        response.raise_for_status()

        content_type = response.headers['Content-Type']

        if content_type.startswith('application/json'):
            return response.json()

        return response._content

    def flatten(self, data, prefix=''):
        output = {}
        i = 0

        for key in data:
            value = data[key] if isinstance(data, dict) else key
            finalKey = prefix + '[' + key +']' if prefix else key
            finalKey = prefix + '[' + str(i) +']' if isinstance(data, list) else finalKey
            i += 1
            
            if isinstance(value, list) or isinstance(value, dict):
                output = {**output, **self.flatten(value, finalKey)}
            else:
                output[finalKey] = value

        return output

