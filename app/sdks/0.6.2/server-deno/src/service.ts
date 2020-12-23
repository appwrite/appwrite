import { Client } from "./client.ts";

export abstract class Service {
    
    client: Client;

    /**
     * @param client
     */
    constructor(client: Client) {
        this.client = client;
    }
}