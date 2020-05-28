import { Service } from "../service.ts";
import { DocumentData } from '../client.ts'

export class Storage extends Service {

    /**
     * List Files
     *
     * Get a list of all the user files. You can use the query params to filter
     * your results. On admin mode, this endpoint will return a list of all of the
     * project files. [Learn more about different API modes](/docs/admin).
     *
     * @param string search
     * @param number limit
     * @param number offset
     * @param string orderType
     * @throws Exception
     * @return Promise<string>
     */
    async listFiles(search: string = '', limit: number = 25, offset: number = 0, orderType: string = 'ASC'): Promise<string> {
        let path = '/storage/files';
        
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
     * Create File
     *
     * Create a new file. The user who creates the file will automatically be
     * assigned to read and write access unless he has passed custom values for
     * read and write arguments.
     *
     * @param File | Blob file
     * @param Array<string> read
     * @param Array<string> write
     * @throws Exception
     * @return Promise<string>
     */
    async createFile(file: File | Blob, read: Array<string>, write: Array<string>): Promise<string> {
        let path = '/storage/files';
        
        return await this.client.call('post', path, {
                    'content-type': 'multipart/form-data',
               },
               {
                'file': file,
                'read': read,
                'write': write
            });
    }

    /**
     * Get File
     *
     * Get file by its unique ID. This endpoint response returns a JSON object
     * with the file metadata.
     *
     * @param string fileId
     * @throws Exception
     * @return Promise<string>
     */
    async getFile(fileId: string): Promise<string> {
        let path = '/storage/files/{fileId}'.replace(new RegExp('{fileId}', 'g'), fileId);
        
        return await this.client.call('get', path, {
                    'content-type': 'application/json',
               },
               {
            });
    }

    /**
     * Update File
     *
     * Update file by its unique ID. Only users with write permissions have access
     * to update this resource.
     *
     * @param string fileId
     * @param Array<string> read
     * @param Array<string> write
     * @throws Exception
     * @return Promise<string>
     */
    async updateFile(fileId: string, read: Array<string>, write: Array<string>): Promise<string> {
        let path = '/storage/files/{fileId}'.replace(new RegExp('{fileId}', 'g'), fileId);
        
        return await this.client.call('put', path, {
                    'content-type': 'application/json',
               },
               {
                'read': read,
                'write': write
            });
    }

    /**
     * Delete File
     *
     * Delete a file by its unique ID. Only users with write permissions have
     * access to delete this resource.
     *
     * @param string fileId
     * @throws Exception
     * @return Promise<string>
     */
    async deleteFile(fileId: string): Promise<string> {
        let path = '/storage/files/{fileId}'.replace(new RegExp('{fileId}', 'g'), fileId);
        
        return await this.client.call('delete', path, {
                    'content-type': 'application/json',
               },
               {
            });
    }

    /**
     * Get File for Download
     *
     * Get file content by its unique ID. The endpoint response return with a
     * 'Content-Disposition: attachment' header that tells the browser to start
     * downloading the file to user downloads directory.
     *
     * @param string fileId
     * @throws Exception
     * @return Promise<string>
     */
    async getFileDownload(fileId: string): Promise<string> {
        let path = '/storage/files/{fileId}/download'.replace(new RegExp('{fileId}', 'g'), fileId);
        
        return await this.client.call('get', path, {
                    'content-type': 'application/json',
               },
               {
            });
    }

    /**
     * Get File Preview
     *
     * Get a file preview image. Currently, this method supports preview for image
     * files (jpg, png, and gif), other supported formats, like pdf, docs, slides,
     * and spreadsheets, will return the file icon image. You can also pass query
     * string arguments for cutting and resizing your preview image.
     *
     * @param string fileId
     * @param number width
     * @param number height
     * @param number quality
     * @param string background
     * @param string output
     * @throws Exception
     * @return Promise<string>
     */
    async getFilePreview(fileId: string, width: number = 0, height: number = 0, quality: number = 100, background: string = '', output: string = ''): Promise<string> {
        let path = '/storage/files/{fileId}/preview'.replace(new RegExp('{fileId}', 'g'), fileId);
        
        return await this.client.call('get', path, {
                    'content-type': 'application/json',
               },
               {
                'width': width,
                'height': height,
                'quality': quality,
                'background': background,
                'output': output
            });
    }

    /**
     * Get File for View
     *
     * Get file content by its unique ID. This endpoint is similar to the download
     * method but returns with no  'Content-Disposition: attachment' header.
     *
     * @param string fileId
     * @param string as
     * @throws Exception
     * @return Promise<string>
     */
    async getFileView(fileId: string, as: string = ''): Promise<string> {
        let path = '/storage/files/{fileId}/view'.replace(new RegExp('{fileId}', 'g'), fileId);
        
        return await this.client.call('get', path, {
                    'content-type': 'application/json',
               },
               {
                'as': as
            });
    }
}