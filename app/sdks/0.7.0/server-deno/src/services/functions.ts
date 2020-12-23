import { Service } from "../service.ts";
import { DocumentData } from '../client.ts'

export class Functions extends Service {

    /**
     * List Functions
     *
     * @param string search
     * @param number limit
     * @param number offset
     * @param string orderType
     * @throws Exception
     * @return Promise<string>
     */
    async list(search: string = '', limit: number = 25, offset: number = 0, orderType: string = 'ASC'): Promise<string> {
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
     * @param DocumentData vars
     * @param Array<any> events
     * @param string schedule
     * @param number timeout
     * @throws Exception
     * @return Promise<string>
     */
    async create(name: string, env: string, vars: DocumentData = [], events: Array<any> = [], schedule: string = '', timeout: number = 15): Promise<string> {
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
     * @return Promise<string>
     */
    async get(functionId: string): Promise<string> {
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
     * @param DocumentData vars
     * @param Array<any> events
     * @param string schedule
     * @param number timeout
     * @throws Exception
     * @return Promise<string>
     */
    async update(functionId: string, name: string, vars: DocumentData = [], events: Array<any> = [], schedule: string = '', timeout: number = 15): Promise<string> {
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
     * @return Promise<string>
     */
    async delete(functionId: string): Promise<string> {
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
     * @return Promise<string>
     */
    async listExecutions(functionId: string, search: string = '', limit: number = 25, offset: number = 0, orderType: string = 'ASC'): Promise<string> {
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
     * @return Promise<string>
     */
    async createExecution(functionId: string): Promise<string> {
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
     * @return Promise<string>
     */
    async getExecution(functionId: string, executionId: string): Promise<string> {
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
     * @return Promise<string>
     */
    async updateTag(functionId: string, tag: string): Promise<string> {
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
     * @return Promise<string>
     */
    async listTags(functionId: string, search: string = '', limit: number = 25, offset: number = 0, orderType: string = 'ASC'): Promise<string> {
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
     * @param File | Blob code
     * @throws Exception
     * @return Promise<string>
     */
    async createTag(functionId: string, command: string, code: File | Blob): Promise<string> {
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
     * @return Promise<string>
     */
    async getTag(functionId: string, tagId: string): Promise<string> {
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
     * @return Promise<string>
     */
    async deleteTag(functionId: string, tagId: string): Promise<string> {
        let path = '/functions/{functionId}/tags/{tagId}'.replace(new RegExp('{functionId}', 'g'), functionId).replace(new RegExp('{tagId}', 'g'), tagId);
        
        return await this.client.call('delete', path, {
                    'content-type': 'application/json',
               },
               {
            });
    }
}