export interface DocumentData {
    [key: string]: any;
}

export class Client {

    endpoint: string = 'https://appwrite.io/v1';
    headers: DocumentData = {
        'content-type': '',
        'x-sdk-version': 'appwrite:deno:0.0.1',
    };
    
    /**
     * Set Project
     *
     * Your project ID
     *
     * @param string value
     *
     * @return self
     */
    setProject(value: string): this {
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
    setKey(value: string): this {
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
    setLocale(value: string): this {
        this.addHeader('X-Appwrite-Locale', value);

        return this;
    }


    /***
     * @param endpoint
     * @return this
     */
    setEndpoint(endpoint: string): this {
        this.endpoint = endpoint;

        return this;
    }

    /**
     * @param key string
     * @param value string
     */
    addHeader(key: string, value: string): this {
        this.headers[key.toLowerCase()] = value.toLowerCase();
        
        return this;
    }

    withoutHeader(key: string, headers: DocumentData): DocumentData {
        return Object.keys(headers).reduce((acc: DocumentData, cv) => {
            if (cv == 'content-type') return acc
            acc[cv] = headers[cv]
            return acc
        }, {})
    }

    async call(method: string, path: string = '', headers: DocumentData = {}, params: DocumentData = {}) {
        headers = Object.assign(this.headers, headers);

        let body;
        const url = new URL(this.endpoint + path)
        if (method.toUpperCase() === 'GET') {
            url.search = new URLSearchParams(this.flatten(params)).toString()
            body = null
        } else if (headers['content-type'].toLowerCase().startsWith('multipart/form-data')) {
            headers = this.withoutHeader('content-type', headers)
            const formData = new FormData()
            const flatParams = this.flatten(params)
            for (const key in flatParams) {
                formData.append(key, flatParams[key]);
            }
            body = formData
        } else {
            body = JSON.stringify(params)
        }
        
        const options = {
            method: method.toUpperCase(),
            headers: headers,
            body: body,
        };

        let response = await fetch(url.toString(), options);
        const contentType = response.headers.get('content-type');

        if (contentType && contentType.includes('application/json')) {
            return response.json()
        }

        return response;
    }

    flatten(data: DocumentData, prefix = '') {
        let output: DocumentData = {};

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