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
     * @param object vars
     * @param string trigger
     * @param string[] events
     * @param string schedule
     * @param number timeout
     * @throws Exception
     * @return {}
     */
    async create(name, vars = [], trigger = 'event', events = [], schedule = '', timeout = 10) {
        let path = '/functions';
        
        return await this.client.call('post', path, {
                    'content-type': 'application/json',
               },
               {
                'name': name,
                'vars': vars,
                'trigger': trigger,
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
     * @param string trigger
     * @param string[] events
     * @param string schedule
     * @param number timeout
     * @throws Exception
     * @return {}
     */
    async update(functionId, name, vars = [], trigger = 'event', events = [], schedule = '', timeout = 10) {
        let path = '/functions/{functionId}'.replace(new RegExp('{functionId}', 'g'), functionId);
        
        return await this.client.call('put', path, {
                    'content-type': 'application/json',
               },
               {
                'name': name,
                'vars': vars,
                'trigger': trigger,
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
     * Update Function Active Tag
     *
     * @param string functionId
     * @param string active
     * @throws Exception
     * @return {}
     */
    async updateTag(functionId, active) {
        let path = '/functions/{functionId}/active'.replace(new RegExp('{functionId}', 'g'), functionId);
        
        return await this.client.call('patch', path, {
                    'content-type': 'application/json',
               },
               {
                'active': active
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
     * @param number async
     * @throws Exception
     * @return {}
     */
    async createExecution(functionId, async = 1) {
        let path = '/functions/{functionId}/executions'.replace(new RegExp('{functionId}', 'g'), functionId);
        
        return await this.client.call('post', path, {
                    'content-type': 'application/json',
               },
               {
                'async': async
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
     * @param string env
     * @param string command
     * @param string code
     * @throws Exception
     * @return {}
     */
    async createTag(functionId, env, command, code) {
        let path = '/functions/{functionId}/tags'.replace(new RegExp('{functionId}', 'g'), functionId);
        
        return await this.client.call('post', path, {
                    'content-type': 'application/json',
               },
               {
                'env': env,
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