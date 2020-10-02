import { Service } from "../service.ts";
import { DocumentData } from '../client.ts'

export class Health extends Service {

    /**
     * Get HTTP
     *
     * Check the Appwrite HTTP server is up and responsive.
     *
     * @throws Exception
     * @return Promise<string>
     */
    async get(): Promise<string> {
        let path = '/health';
        
        return this.client.call('get', path, {
                    'content-type': 'application/json',
               },
               {
            });
    }

    /**
     * Get Anti virus
     *
     * Check the Appwrite Anti Virus server is up and connection is successful.
     *
     * @throws Exception
     * @return Promise<string>
     */
    async getAntiVirus(): Promise<string> {
        let path = '/health/anti-virus';
        
        return this.client.call('get', path, {
                    'content-type': 'application/json',
               },
               {
            });
    }

    /**
     * Get Cache
     *
     * Check the Appwrite in-memory cache server is up and connection is
     * successful.
     *
     * @throws Exception
     * @return Promise<string>
     */
    async getCache(): Promise<string> {
        let path = '/health/cache';
        
        return this.client.call('get', path, {
                    'content-type': 'application/json',
               },
               {
            });
    }

    /**
     * Get DB
     *
     * Check the Appwrite database server is up and connection is successful.
     *
     * @throws Exception
     * @return Promise<string>
     */
    async getDB(): Promise<string> {
        let path = '/health/db';
        
        return this.client.call('get', path, {
                    'content-type': 'application/json',
               },
               {
            });
    }

    /**
     * Get Certificate Queue
     *
     * Get the number of certificates that are waiting to be issued against
     * [Letsencrypt](https://letsencrypt.org/) in the Appwrite internal queue
     * server.
     *
     * @throws Exception
     * @return Promise<string>
     */
    async getQueueCertificates(): Promise<string> {
        let path = '/health/queue/certificates';
        
        return this.client.call('get', path, {
                    'content-type': 'application/json',
               },
               {
            });
    }

    /**
     * Get Functions Queue
     *
     * @throws Exception
     * @return Promise<string>
     */
    async getQueueFunctions(): Promise<string> {
        let path = '/health/queue/functions';
        
        return this.client.call('get', path, {
                    'content-type': 'application/json',
               },
               {
            });
    }

    /**
     * Get Logs Queue
     *
     * Get the number of logs that are waiting to be processed in the Appwrite
     * internal queue server.
     *
     * @throws Exception
     * @return Promise<string>
     */
    async getQueueLogs(): Promise<string> {
        let path = '/health/queue/logs';
        
        return this.client.call('get', path, {
                    'content-type': 'application/json',
               },
               {
            });
    }

    /**
     * Get Tasks Queue
     *
     * Get the number of tasks that are waiting to be processed in the Appwrite
     * internal queue server.
     *
     * @throws Exception
     * @return Promise<string>
     */
    async getQueueTasks(): Promise<string> {
        let path = '/health/queue/tasks';
        
        return this.client.call('get', path, {
                    'content-type': 'application/json',
               },
               {
            });
    }

    /**
     * Get Usage Queue
     *
     * Get the number of usage stats that are waiting to be processed in the
     * Appwrite internal queue server.
     *
     * @throws Exception
     * @return Promise<string>
     */
    async getQueueUsage(): Promise<string> {
        let path = '/health/queue/usage';
        
        return this.client.call('get', path, {
                    'content-type': 'application/json',
               },
               {
            });
    }

    /**
     * Get Webhooks Queue
     *
     * Get the number of webhooks that are waiting to be processed in the Appwrite
     * internal queue server.
     *
     * @throws Exception
     * @return Promise<string>
     */
    async getQueueWebhooks(): Promise<string> {
        let path = '/health/queue/webhooks';
        
        return this.client.call('get', path, {
                    'content-type': 'application/json',
               },
               {
            });
    }

    /**
     * Get Local Storage
     *
     * Check the Appwrite local storage device is up and connection is successful.
     *
     * @throws Exception
     * @return Promise<string>
     */
    async getStorageLocal(): Promise<string> {
        let path = '/health/storage/local';
        
        return this.client.call('get', path, {
                    'content-type': 'application/json',
               },
               {
            });
    }

    /**
     * Get Time
     *
     * Check the Appwrite server time is synced with Google remote NTP server. We
     * use this technology to smoothly handle leap seconds with no disruptive
     * events. The [Network Time
     * Protocol](https://en.wikipedia.org/wiki/Network_Time_Protocol) (NTP) is
     * used by hundreds of millions of computers and devices to synchronize their
     * clocks over the Internet. If your computer sets its own clock, it likely
     * uses NTP.
     *
     * @throws Exception
     * @return Promise<string>
     */
    async getTime(): Promise<string> {
        let path = '/health/time';
        
        return this.client.call('get', path, {
                    'content-type': 'application/json',
               },
               {
            });
    }
}