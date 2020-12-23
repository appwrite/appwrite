const Service = require('../service.js');

class Functions extends Service {

    /**
     * List Functions
     *
     * @param string search
     * @param number limit
     * @param number offset
     * @param string orderType
     * @throws Exception
     * @return {}
     */
    async list(search = '', limit = 25, offset = 0, orderType = 'ASC') {
        let path = '/functions';
        
        return await this.client.call('get', path, {
                    'content-type': 'application/json',
               },
               {
                'search': search,
                'limit': limit,
                'offset': offset,
                'orderType': orderType
            });
    }

    /**
     * Create Function
     *
     * @param string name
     * @param string env
     * @param object vars
     * @param string[] events
     * @param string schedule
     * @param number timeout
     * @throws Exception
     * @return {}
     */
    async create(name, env, vars = [], events = [], schedule = '', timeout = 15) {
        let path = '/functions';
        
        return await this.client.call('post', path, {
                    'content-type': 'application/json',
               },
               {
                'name': name,
                'env': env,
                'vars': vars,
                'events': events,
                'schedule': schedule,
                'timeout': timeout
            });
    }

    /**
     * Get Function
     *
     * @param string functionId
     * @throws Exception
     * @return {}
     */
    async get(functionId) {
        let path = '/functions/{functionId}'.replace(new RegExp('{functionId}', 'g'), functionId);
        
        return await this.client.call('get', path, {
                    'content-type': 'application/json',
               },
               {
            });
    }

    /**
     * Update Function
     *
     * @param string functionId
     * @param string name
     * @param object vars
     * @param string[] events
     * @param string schedule
     * @param number timeout
     * @throws Exception
     * @return {}
     */
    async update(functionId, name, vars = [], events = [], schedule = '', timeout = 15) {
        let path = '/functions/{functionId}'.replace(new RegExp('{functionId}', 'g'), functionId);
        
        return await this.client.call('put', path, {
                    'content-type': 'application/json',
               },
               {
                'name': name,
                'vars': vars,
                'events': events,
                'schedule': schedule,
                'timeout': timeout
            });
    }

    /**
     * Delete Function
     *
     * @param string functionId
     * @throws Exception
     * @return {}
     */
    async delete(functionId) {
        let path = '/functions/{functionId}'.replace(new RegExp('{functionId}', 'g'), functionId);
        
        return await this.client.call('delete', path, {
                    'content-type': 'application/json',
               },
               {
            });
    }

    /**
     * List Executions
     *
     * @param string functionId
     * @param string search
     * @param number limit
     * @param number offset
     * @param string orderType
     * @throws Exception
     * @return {}
     */
    async listExecutions(functionId, search = '', limit = 25, offset = 0, orderType = 'ASC') {
        let path = '/functions/{functionId}/executions'.replace(new RegExp('{functionId}', 'g'), functionId);
        
        return await this.client.call('get', path, {
                    'content-type': 'application/json',
               },
               {
                'search': search,
                'limit': limit,
                'offset': offset,
                'orderType': orderType
            });
    }

    /**
     * Create Execution
     *
     * @param string functionId
     * @throws Exception
     * @return {}
     */
    async createExecution(functionId) {
        let path = '/functions/{functionId}/executions'.replace(new RegExp('{functionId}', 'g'), functionId);
        
        return await this.client.call('post', path, {
                    'content-type': 'application/json',
               },
               {
            });
    }

    /**
     * Get Execution
     *
     * @param string functionId
     * @param string executionId
     * @throws Exception
     * @return {}
     */
    async getExecution(functionId, executionId) {
        let path = '/functions/{functionId}/executions/{executionId}'.replace(new RegExp('{functionId}', 'g'), functionId).replace(new RegExp('{executionId}', 'g'), executionId);
        
        return await this.client.call('get', path, {
                    'content-type': 'application/json',
               },
               {
            });
    }

    /**
     * Update Function Tag
     *
     * @param string functionId
     * @param string tag
     * @throws Exception
     * @return {}
     */
    async updateTag(functionId, tag) {
        let path = '/functions/{functionId}/tag'.replace(new RegExp('{functionId}', 'g'), functionId);
        
        return await this.client.call('patch', path, {
                    'content-type': 'application/json',
               },
               {
                'tag': tag
            });
    }

    /**
     * List Tags
     *
     * @param string functionId
     * @param string search
     * @param number limit
     * @param number offset
     * @param string orderType
     * @throws Exception
     * @return {}
     */
    async listTags(functionId, search = '', limit = 25, offset = 0, orderType = 'ASC') {
        let path = '/functions/{functionId}/tags'.replace(new RegExp('{functionId}', 'g'), functionId);
        
        return await this.client.call('get', path, {
                    'content-type': 'application/json',
               },
               {
                'search': search,
                'limit': limit,
                'offset': offset,
                'orderType': orderType
            });
    }

    /**
     * Create Tag
     *
     * @param string functionId
     * @param string command
     * @param File code
     * @throws Exception
     * @return {}
     */
    async createTag(functionId, command, code) {
        let path = '/functions/{functionId}/tags'.replace(new RegExp('{functionId}', 'g'), functionId);
        
        return await this.client.call('post', path, {
                    'content-type': 'multipart/form-data',
               },
               {
                'command': command,
                'code': code
            });
    }

    /**
     * Get Tag
     *
     * @param string functionId
     * @param string tagId
     * @throws Exception
     * @return {}
     */
    async getTag(functionId, tagId) {
        let path = '/functions/{functionId}/tags/{tagId}'.replace(new RegExp('{functionId}', 'g'), functionId).replace(new RegExp('{tagId}', 'g'), tagId);
        
        return await this.client.call('get', path, {
                    'content-type': 'application/json',
               },
               {
            });
    }

    /**
     * Delete Tag
     *
     * @param string functionId
     * @param string tagId
     * @throws Exception
     * @return {}
     */
    async deleteTag(functionId, tagId) {
        let path = '/functions/{functionId}/tags/{tagId}'.replace(new RegExp('{functionId}', 'g'), functionId).replace(new RegExp('{tagId}', 'g'), tagId);
        
        return await this.client.call('delete', path, {
                    'content-type': 'application/json',
               },
               {
            });
    }
}

module.exports = Functions;