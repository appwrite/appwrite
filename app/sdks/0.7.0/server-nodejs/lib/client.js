const URL = require('url').URL;
const axios = require('axios');
const FormData = require('form-data');

class Client {
    
    constructor() {
        this.endpoint = 'https://appwrite.io/v1';
        this.headers = {
            'content-type': '',
            'x-sdk-version': 'appwrite:nodejs:1.1.0',
        };
        this.selfSigned = false;
    }

    /**
     * Set Project
     *
     * Your project ID
     *
     * @param string value
     *
     * @return self
     */
    setProject(value) {
        this.addHeader('X-Appwrite-Project', value);

        return this;
    }

    /**
     * Set Key
     *
     * Your secret API key
     *
     * @param string value
     *
     * @return self
     */
    setKey(value) {
        this.addHeader('X-Appwrite-Key', value);

        return this;
    }

    /**
     * Set Locale
     *
     * @param string value
     *
     * @return self
     */
    setLocale(value) {
        this.addHeader('X-Appwrite-Locale', value);

        return this;
    }

    /***
     * @param bool status
     * @return this
     */
    setSelfSigned(status = true) {
        this.selfSigned = status;

        return this;
    }

    /***
     * @param endpoint
     * @return this
     */
    setEndpoint(endpoint)
    {
        this.endpoint = endpoint;

        return this;
    }

    /**
     * @param key string
     * @param value string
     */
    addHeader(key, value) {
        this.headers[key.toLowerCase()] = value.toLowerCase();
        
        return this;
    }
      
    async call(method, path = '', headers = {}, params = {}) {
        if(this.selfSigned) { // Allow self signed requests
            process.env["NODE_TLS_REJECT_UNAUTHORIZED"] = 0;
        }

        headers = Object.assign(this.headers, headers);

        let contentType = headers['content-type'].toLowerCase();

        let formData = null;

        // Compute FormData for axios and appwrite.
        if (contentType.startsWith('multipart/form-data')) {
            const form = new FormData();
            
            let flatParams = this.flatten(params);
            
            for (const key in flatParams) {
                form.append(key, flatParams[key])
            }

            headers = {
                ...headers,
                ...form.getHeaders()
            };

            formData = form;
        }

        let options = {
            method: method.toUpperCase(),
            url: this.endpoint + path,
            params: (method.toUpperCase() === 'GET') ? params : {},
            headers: headers,
            data: (method.toUpperCase() === 'GET' || contentType.startsWith('multipart/form-data')) ? formData : params,
            json: (contentType.startsWith('application/json'))
        };

        let response = await axios(options);

        return response.data;
    }

    flatten(data, prefix = '') {
        let output = {};

        for (const key in data) {
            let value = data[key];
            let finalKey = prefix ? prefix + '[' + key +']' : key;

            if (Array.isArray(value)) {
                output = Object.assign(output, this.flatten(value, finalKey)); // @todo: handle name collision here if needed
            }
            else {
                output[finalKey] = value;
            }
        }

        return output;
    }
}

module.exports = Client;