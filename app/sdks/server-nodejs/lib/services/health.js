const Service = require('../service.js');

class Health extends Service {

    /**
     * Check API HTTP Health
     *
     * Check the Appwrite HTTP server is up and responsive.
     *
     * @throws Exception
     * @return {}
     */
    async get() {
        let path = '/health';
        
        return await this.client.call('get', path, {
                    'content-type': 'application/json',
               },
               {
            });
    }

    /**
     * Check Cache Health
     *
     * Check the Appwrite in-memory cache server is up and connection is
     * successful.
     *
     * @throws Exception
     * @return {}
     */
    async getCache() {
        let path = '/health/cache';
        
        return await this.client.call('get', path, {
                    'content-type': 'application/json',
               },
               {
            });
    }

    /**
     * Check DB Health
     *
     * Check the Appwrite database server is up and connection is successful.
     *
     * @throws Exception
     * @return {}
     */
    async getDB() {
        let path = '/health/db';
        
        return await this.client.call('get', path, {
                    'content-type': 'application/json',
               },
               {
            });
    }

    /**
     * Check the number of pending certificate messages
     *
     * Get the number of certificates that are waiting to be issued against
     * [Letsencrypt](https://letsencrypt.org/) in the Appwrite internal queue
     * server.
     *
     * @throws Exception
     * @return {}
     */
    async getQueueCertificates() {
        let path = '/health/queue/certificates';
        
        return await this.client.call('get', path, {
                    'content-type': 'application/json',
               },
               {
            });
    }

    /**
     * Check the number of pending functions messages
     *
     * @throws Exception
     * @return {}
     */
    async getQueueFunctions() {
        let path = '/health/queue/functions';
        
        return await this.client.call('get', path, {
                    'content-type': 'application/json',
               },
               {
            });
    }

    /**
     * Check the number of pending log messages
     *
     * Get the number of logs that are waiting to be processed in the Appwrite
     * internal queue server.
     *
     * @throws Exception
     * @return {}
     */
    async getQueueLogs() {
        let path = '/health/queue/logs';
        
        return await this.client.call('get', path, {
                    'content-type': 'application/json',
               },
               {
            });
    }

    /**
     * Check the number of pending task messages
     *
     * Get the number of tasks that are waiting to be processed in the Appwrite
     * internal queue server.
     *
     * @throws Exception
     * @return {}
     */
    async getQueueTasks() {
        let path = '/health/queue/tasks';
        
        return await this.client.call('get', path, {
                    'content-type': 'application/json',
               },
               {
            });
    }

    /**
     * Check the number of pending usage messages
     *
     * Get the number of usage stats that are waiting to be processed in the
     * Appwrite internal queue server.
     *
     * @throws Exception
     * @return {}
     */
    async getQueueUsage() {
        let path = '/health/queue/usage';
        
        return await this.client.call('get', path, {
                    'content-type': 'application/json',
               },
               {
            });
    }

    /**
     * Check number of pending webhook messages
     *
     * Get the number of webhooks that are waiting to be processed in the Appwrite
     * internal queue server.
     *
     * @throws Exception
     * @return {}
     */
    async getQueueWebhooks() {
        let path = '/health/queue/webhooks';
        
        return await this.client.call('get', path, {
                    'content-type': 'application/json',
               },
               {
            });
    }

    /**
     * Check Anti virus Health
     *
     * Check the Appwrite Anti Virus server is up and connection is successful.
     *
     * @throws Exception
     * @return {}
     */
    async getStorageAntiVirus() {
        let path = '/health/storage/anti-virus';
        
        return await this.client.call('get', path, {
                    'content-type': 'application/json',
               },
               {
            });
    }

    /**
     * Check File System Health
     *
     * Check the Appwrite local storage device is up and connection is successful.
     *
     * @throws Exception
     * @return {}
     */
    async getStorageLocal() {
        let path = '/health/storage/local';
        
        return await this.client.call('get', path, {
                    'content-type': 'application/json',
               },
               {
            });
    }

    /**
     * Check Time Health
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
     * @return {}
     */
    async getTime() {
        let path = '/health/time';
        
        return await this.client.call('get', path, {
                    'content-type': 'application/json',
               },
               {
            });
    }
}

module.exports = Health;