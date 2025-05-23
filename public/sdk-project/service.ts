import { AppwriteException, Client } from './client';
import type { Payload } from './client';

export class Service {
    static CHUNK_SIZE = 5*1024*1024; // 5MB

    client: Client;

    constructor(client: Client) {
        this.client = client;
    }

    static flatten(data: Payload, prefix = ''): Payload {
        let output: Payload = {};

        for (const key in data) {
            let value = data[key];
            let finalKey = prefix ? `${prefix}[${key}]` : key;

            if (Array.isArray(value)) {
                output = Object.assign(output, this.flatten(value, finalKey));
            }
            else {
                output[finalKey] = value;
            }
        }

        return output;
    }
    
    /**
     * Validate Required Parameters
     * 
     * Use this function to validate that all required parameters are present in the provided object.
     * If any required parameters are missing, this function will throw an `AppwriteException` with a message
     * 
     * @param {Record<string, any>} params - The parameters to validate.
     * 
     * @throws {TypeError} If the `params` argument is not an object.
     */
    static validateRequiredParameters(params: Record<string, any>): void {
        if(typeof params !== 'object') {
            throw new TypeError('The params argument must be an object.');
        }

        for(const key in params) {
            if(typeof params[key] === 'undefined') {
                throw new AppwriteException(`Missing required parameter: "${key}"`);
            }
        }
    }
}