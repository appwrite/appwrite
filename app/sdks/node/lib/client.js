const URL = require('url').URL;
const request = require('request-promise-native');

class Client {
    
    constructor() {
        this.endpoint = 'https://appwrite.io/v1';
        this.headers = {
            'content-type': '',
            'x-sdk-version': 'appwrite:nodejs:1.0.27',
        };
        this.selfSigned = false;
    }

    /**
     * Set Project
     *
     * Your Appwrite project ID
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
     * Your Appwrite project secret key
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

    /**
     * Set Mode
     *
     * @param string value
     *
     * @return self
     */
    setMode(value) {
        this.addHeader('X-Appwrite-Mode', value);

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
      
    call(method, path = '', headers = {}, params = {}) {
        if(this.selfSigned) { // Allow self signed requests
            process.env["NODE_TLS_REJECT_UNAUTHORIZED"] = 0;
        }

        headers = Object.assign(this.headers, headers);
        
        let options = {
            method: method.toUpperCase(),
            uri: this.endpoint + path,
            qs: (method.toUpperCase() === 'GET') ? params : {},
            headers: headers,
            body: (method.toUpperCase() === 'GET') ? '' : params,
            json: (headers['content-type'].toLowerCase().startsWith('application/json')),
        };

        return request(options);      
    }
}

module.exports = Client;