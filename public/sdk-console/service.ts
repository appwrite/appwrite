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
}