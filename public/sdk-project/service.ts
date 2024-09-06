import { Client } from './client';
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
     * Populates a payload object with key-value pairs
     * 
     * Use this function to populate a payload object with key-value pairs from another object.
     * This function performs a shallow copy of all defined key-value pairs from the `values` object into the `payload`.
     * This function directly modifies the original `payload` object, making it more efficient for large-scale applications.
     * 
     * @param {Payload} payload - The payload object to populate
     * @param {Record<string, any>} values - The key-value pairs to populate the payload with
     * 
     * @example
     * const payload: Payload = {};
     * const values = { key1: 'value1', key2: 'value2' };
     * 
     * Service.populatePayload(payload, values);
     * console.log(payload); // { key1: 'value1', key2: 'value2' }
     * 
     * @throws {TypeError} - If the payload is not an object
     * @throws {TypeError} - If the values are not an object
     */
    static populatePayload(payload: Payload, values: Record<string, any>): void {
        if (typeof payload !== 'object') {
            throw new TypeError('Payload must be an object');
        }

        if (typeof values !== 'object') {
            throw new TypeError('Values must be an object');
        }

        for (const key in values) {
            if(typeof values[key] !== 'undefined') {
                payload[key] = values[key];
            }
        }
    }
}