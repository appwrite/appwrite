const Service = require('../service.js');

class Projects extends Service {

    /**
     * List Projects
     *
     * @throws Exception
     * @return {}
     */
    async listProjects() {
        let path = '/projects';
        
        return await this.client.call('get', path, {
                    'content-type': 'application/json',
               },
               {
            });
    }

    /**
     * Create Project
     *
     * @param string name
     * @param string teamId
     * @param string description
     * @param string logo
     * @param string url
     * @param string legalName
     * @param string legalCountry
     * @param string legalState
     * @param string legalCity
     * @param string legalAddress
     * @param string legalTaxId
     * @throws Exception
     * @return {}
     */
    async createProject(name, teamId, description = '', logo = '', url = '', legalName = '', legalCountry = '', legalState = '', legalCity = '', legalAddress = '', legalTaxId = '') {
        let path = '/projects';
        
        return await this.client.call('post', path, {
                    'content-type': 'application/json',
               },
               {
                'name': name,
                'teamId': teamId,
                'description': description,
                'logo': logo,
                'url': url,
                'legalName': legalName,
                'legalCountry': legalCountry,
                'legalState': legalState,
                'legalCity': legalCity,
                'legalAddress': legalAddress,
                'legalTaxId': legalTaxId
            });
    }

    /**
     * Get Project
     *
     * @param string projectId
     * @throws Exception
     * @return {}
     */
    async getProject(projectId) {
        let path = '/projects/{projectId}'.replace(new RegExp('{projectId}', 'g'), projectId);
        
        return await this.client.call('get', path, {
                    'content-type': 'application/json',
               },
               {
            });
    }

    /**
     * Update Project
     *
     * @param string projectId
     * @param string name
     * @param string description
     * @param string logo
     * @param string url
     * @param string legalName
     * @param string legalCountry
     * @param string legalState
     * @param string legalCity
     * @param string legalAddress
     * @param string legalTaxId
     * @throws Exception
     * @return {}
     */
    async updateProject(projectId, name, description = '', logo = '', url = '', legalName = '', legalCountry = '', legalState = '', legalCity = '', legalAddress = '', legalTaxId = '') {
        let path = '/projects/{projectId}'.replace(new RegExp('{projectId}', 'g'), projectId);
        
        return await this.client.call('patch', path, {
                    'content-type': 'application/json',
               },
               {
                'name': name,
                'description': description,
                'logo': logo,
                'url': url,
                'legalName': legalName,
                'legalCountry': legalCountry,
                'legalState': legalState,
                'legalCity': legalCity,
                'legalAddress': legalAddress,
                'legalTaxId': legalTaxId
            });
    }

    /**
     * Delete Project
     *
     * @param string projectId
     * @throws Exception
     * @return {}
     */
    async deleteProject(projectId) {
        let path = '/projects/{projectId}'.replace(new RegExp('{projectId}', 'g'), projectId);
        
        return await this.client.call('delete', path, {
                    'content-type': 'application/json',
               },
               {
            });
    }

    /**
     * List Keys
     *
     * @param string projectId
     * @throws Exception
     * @return {}
     */
    async listKeys(projectId) {
        let path = '/projects/{projectId}/keys'.replace(new RegExp('{projectId}', 'g'), projectId);
        
        return await this.client.call('get', path, {
                    'content-type': 'application/json',
               },
               {
            });
    }

    /**
     * Create Key
     *
     * @param string projectId
     * @param string name
     * @param array scopes
     * @throws Exception
     * @return {}
     */
    async createKey(projectId, name, scopes) {
        let path = '/projects/{projectId}/keys'.replace(new RegExp('{projectId}', 'g'), projectId);
        
        return await this.client.call('post', path, {
                    'content-type': 'application/json',
               },
               {
                'name': name,
                'scopes': scopes
            });
    }

    /**
     * Get Key
     *
     * @param string projectId
     * @param string keyId
     * @throws Exception
     * @return {}
     */
    async getKey(projectId, keyId) {
        let path = '/projects/{projectId}/keys/{keyId}'.replace(new RegExp('{projectId}', 'g'), projectId).replace(new RegExp('{keyId}', 'g'), keyId);
        
        return await this.client.call('get', path, {
                    'content-type': 'application/json',
               },
               {
            });
    }

    /**
     * Update Key
     *
     * @param string projectId
     * @param string keyId
     * @param string name
     * @param array scopes
     * @throws Exception
     * @return {}
     */
    async updateKey(projectId, keyId, name, scopes) {
        let path = '/projects/{projectId}/keys/{keyId}'.replace(new RegExp('{projectId}', 'g'), projectId).replace(new RegExp('{keyId}', 'g'), keyId);
        
        return await this.client.call('put', path, {
                    'content-type': 'application/json',
               },
               {
                'name': name,
                'scopes': scopes
            });
    }

    /**
     * Delete Key
     *
     * @param string projectId
     * @param string keyId
     * @throws Exception
     * @return {}
     */
    async deleteKey(projectId, keyId) {
        let path = '/projects/{projectId}/keys/{keyId}'.replace(new RegExp('{projectId}', 'g'), projectId).replace(new RegExp('{keyId}', 'g'), keyId);
        
        return await this.client.call('delete', path, {
                    'content-type': 'application/json',
               },
               {
            });
    }

    /**
     * Update Project OAuth
     *
     * @param string projectId
     * @param string provider
     * @param string appId
     * @param string secret
     * @throws Exception
     * @return {}
     */
    async updateProjectOAuth(projectId, provider, appId = '', secret = '') {
        let path = '/projects/{projectId}/oauth'.replace(new RegExp('{projectId}', 'g'), projectId);
        
        return await this.client.call('patch', path, {
                    'content-type': 'application/json',
               },
               {
                'provider': provider,
                'appId': appId,
                'secret': secret
            });
    }

    /**
     * List Platforms
     *
     * @param string projectId
     * @throws Exception
     * @return {}
     */
    async listPlatforms(projectId) {
        let path = '/projects/{projectId}/platforms'.replace(new RegExp('{projectId}', 'g'), projectId);
        
        return await this.client.call('get', path, {
                    'content-type': 'application/json',
               },
               {
            });
    }

    /**
     * Create Platform
     *
     * @param string projectId
     * @param string type
     * @param string name
     * @param string key
     * @param string store
     * @param string url
     * @throws Exception
     * @return {}
     */
    async createPlatform(projectId, type, name, key = '', store = '', url = '') {
        let path = '/projects/{projectId}/platforms'.replace(new RegExp('{projectId}', 'g'), projectId);
        
        return await this.client.call('post', path, {
                    'content-type': 'application/json',
               },
               {
                'type': type,
                'name': name,
                'key': key,
                'store': store,
                'url': url
            });
    }

    /**
     * Get Platform
     *
     * @param string projectId
     * @param string platformId
     * @throws Exception
     * @return {}
     */
    async getPlatform(projectId, platformId) {
        let path = '/projects/{projectId}/platforms/{platformId}'.replace(new RegExp('{projectId}', 'g'), projectId).replace(new RegExp('{platformId}', 'g'), platformId);
        
        return await this.client.call('get', path, {
                    'content-type': 'application/json',
               },
               {
            });
    }

    /**
     * Update Platform
     *
     * @param string projectId
     * @param string platformId
     * @param string name
     * @param string key
     * @param string store
     * @param string url
     * @throws Exception
     * @return {}
     */
    async updatePlatform(projectId, platformId, name, key = '', store = '', url = '') {
        let path = '/projects/{projectId}/platforms/{platformId}'.replace(new RegExp('{projectId}', 'g'), projectId).replace(new RegExp('{platformId}', 'g'), platformId);
        
        return await this.client.call('put', path, {
                    'content-type': 'application/json',
               },
               {
                'name': name,
                'key': key,
                'store': store,
                'url': url
            });
    }

    /**
     * Delete Platform
     *
     * @param string projectId
     * @param string platformId
     * @throws Exception
     * @return {}
     */
    async deletePlatform(projectId, platformId) {
        let path = '/projects/{projectId}/platforms/{platformId}'.replace(new RegExp('{projectId}', 'g'), projectId).replace(new RegExp('{platformId}', 'g'), platformId);
        
        return await this.client.call('delete', path, {
                    'content-type': 'application/json',
               },
               {
            });
    }

    /**
     * List Tasks
     *
     * @param string projectId
     * @throws Exception
     * @return {}
     */
    async listTasks(projectId) {
        let path = '/projects/{projectId}/tasks'.replace(new RegExp('{projectId}', 'g'), projectId);
        
        return await this.client.call('get', path, {
                    'content-type': 'application/json',
               },
               {
            });
    }

    /**
     * Create Task
     *
     * @param string projectId
     * @param string name
     * @param string status
     * @param string schedule
     * @param number security
     * @param string httpMethod
     * @param string httpUrl
     * @param array httpHeaders
     * @param string httpUser
     * @param string httpPass
     * @throws Exception
     * @return {}
     */
    async createTask(projectId, name, status, schedule, security, httpMethod, httpUrl, httpHeaders = [], httpUser = '', httpPass = '') {
        let path = '/projects/{projectId}/tasks'.replace(new RegExp('{projectId}', 'g'), projectId);
        
        return await this.client.call('post', path, {
                    'content-type': 'application/json',
               },
               {
                'name': name,
                'status': status,
                'schedule': schedule,
                'security': security,
                'httpMethod': httpMethod,
                'httpUrl': httpUrl,
                'httpHeaders': httpHeaders,
                'httpUser': httpUser,
                'httpPass': httpPass
            });
    }

    /**
     * Get Task
     *
     * @param string projectId
     * @param string taskId
     * @throws Exception
     * @return {}
     */
    async getTask(projectId, taskId) {
        let path = '/projects/{projectId}/tasks/{taskId}'.replace(new RegExp('{projectId}', 'g'), projectId).replace(new RegExp('{taskId}', 'g'), taskId);
        
        return await this.client.call('get', path, {
                    'content-type': 'application/json',
               },
               {
            });
    }

    /**
     * Update Task
     *
     * @param string projectId
     * @param string taskId
     * @param string name
     * @param string status
     * @param string schedule
     * @param number security
     * @param string httpMethod
     * @param string httpUrl
     * @param array httpHeaders
     * @param string httpUser
     * @param string httpPass
     * @throws Exception
     * @return {}
     */
    async updateTask(projectId, taskId, name, status, schedule, security, httpMethod, httpUrl, httpHeaders = [], httpUser = '', httpPass = '') {
        let path = '/projects/{projectId}/tasks/{taskId}'.replace(new RegExp('{projectId}', 'g'), projectId).replace(new RegExp('{taskId}', 'g'), taskId);
        
        return await this.client.call('put', path, {
                    'content-type': 'application/json',
               },
               {
                'name': name,
                'status': status,
                'schedule': schedule,
                'security': security,
                'httpMethod': httpMethod,
                'httpUrl': httpUrl,
                'httpHeaders': httpHeaders,
                'httpUser': httpUser,
                'httpPass': httpPass
            });
    }

    /**
     * Delete Task
     *
     * @param string projectId
     * @param string taskId
     * @throws Exception
     * @return {}
     */
    async deleteTask(projectId, taskId) {
        let path = '/projects/{projectId}/tasks/{taskId}'.replace(new RegExp('{projectId}', 'g'), projectId).replace(new RegExp('{taskId}', 'g'), taskId);
        
        return await this.client.call('delete', path, {
                    'content-type': 'application/json',
               },
               {
            });
    }

    /**
     * Get Project
     *
     * @param string projectId
     * @throws Exception
     * @return {}
     */
    async getProjectUsage(projectId) {
        let path = '/projects/{projectId}/usage'.replace(new RegExp('{projectId}', 'g'), projectId);
        
        return await this.client.call('get', path, {
                    'content-type': 'application/json',
               },
               {
            });
    }

    /**
     * List Webhooks
     *
     * @param string projectId
     * @throws Exception
     * @return {}
     */
    async listWebhooks(projectId) {
        let path = '/projects/{projectId}/webhooks'.replace(new RegExp('{projectId}', 'g'), projectId);
        
        return await this.client.call('get', path, {
                    'content-type': 'application/json',
               },
               {
            });
    }

    /**
     * Create Webhook
     *
     * @param string projectId
     * @param string name
     * @param array events
     * @param string url
     * @param number security
     * @param string httpUser
     * @param string httpPass
     * @throws Exception
     * @return {}
     */
    async createWebhook(projectId, name, events, url, security, httpUser = '', httpPass = '') {
        let path = '/projects/{projectId}/webhooks'.replace(new RegExp('{projectId}', 'g'), projectId);
        
        return await this.client.call('post', path, {
                    'content-type': 'application/json',
               },
               {
                'name': name,
                'events': events,
                'url': url,
                'security': security,
                'httpUser': httpUser,
                'httpPass': httpPass
            });
    }

    /**
     * Get Webhook
     *
     * @param string projectId
     * @param string webhookId
     * @throws Exception
     * @return {}
     */
    async getWebhook(projectId, webhookId) {
        let path = '/projects/{projectId}/webhooks/{webhookId}'.replace(new RegExp('{projectId}', 'g'), projectId).replace(new RegExp('{webhookId}', 'g'), webhookId);
        
        return await this.client.call('get', path, {
                    'content-type': 'application/json',
               },
               {
            });
    }

    /**
     * Update Webhook
     *
     * @param string projectId
     * @param string webhookId
     * @param string name
     * @param array events
     * @param string url
     * @param number security
     * @param string httpUser
     * @param string httpPass
     * @throws Exception
     * @return {}
     */
    async updateWebhook(projectId, webhookId, name, events, url, security, httpUser = '', httpPass = '') {
        let path = '/projects/{projectId}/webhooks/{webhookId}'.replace(new RegExp('{projectId}', 'g'), projectId).replace(new RegExp('{webhookId}', 'g'), webhookId);
        
        return await this.client.call('put', path, {
                    'content-type': 'application/json',
               },
               {
                'name': name,
                'events': events,
                'url': url,
                'security': security,
                'httpUser': httpUser,
                'httpPass': httpPass
            });
    }

    /**
     * Delete Webhook
     *
     * @param string projectId
     * @param string webhookId
     * @throws Exception
     * @return {}
     */
    async deleteWebhook(projectId, webhookId) {
        let path = '/projects/{projectId}/webhooks/{webhookId}'.replace(new RegExp('{projectId}', 'g'), projectId).replace(new RegExp('{webhookId}', 'g'), webhookId);
        
        return await this.client.call('delete', path, {
                    'content-type': 'application/json',
               },
               {
            });
    }
}

module.exports = Projects;