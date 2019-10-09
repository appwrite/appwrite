const Service = require('../service.js');

class Storage extends Service {

    /**
     * List Files
     *
     * /docs/references/storage/list-files.md
     *
     * @param string search
     * @param number limit
     * @param number offset
     * @param string orderType
     * @throws Exception
     * @return {}
     */
    async listFiles(search = '', limit = 25, offset = 0, orderType = 'ASC') {
        let path = '/storage/files';
        
        return await this.client.call('get', path, {'content-type': 'application/json'},
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
     * /docs/references/storage/create-file.md
     *
     * @param File files
     * @param array read
     * @param array write
     * @param string folderId
     * @throws Exception
     * @return {}
     */
    async createFile(files, read = [], write = [], folderId = '') {
        let path = '/storage/files';
        
        return await this.client.call('post', path, {'content-type': 'application/json'},
            {
                'files': files,
                'read': read,
                'write': write,
                'folderId': folderId
            });
    }

    /**
     * Get File
     *
     * /docs/references/storage/get-file.md
     *
     * @param string fileId
     * @throws Exception
     * @return {}
     */
    async getFile(fileId) {
        let path = '/storage/files/{fileId}'.replace(new RegExp('{fileId}', 'g'), fileId);
        
        return await this.client.call('get', path, {'content-type': 'application/json'},
            {
            });
    }

    /**
     * Update File
     *
     * /docs/references/storage/update-file.md
     *
     * @param string fileId
     * @param array read
     * @param array write
     * @param string folderId
     * @throws Exception
     * @return {}
     */
    async updateFile(fileId, read = [], write = [], folderId = '') {
        let path = '/storage/files/{fileId}'.replace(new RegExp('{fileId}', 'g'), fileId);
        
        return await this.client.call('put', path, {'content-type': 'application/json'},
            {
                'read': read,
                'write': write,
                'folderId': folderId
            });
    }

    /**
     * Delete File
     *
     * /docs/references/storage/delete-file.md
     *
     * @param string fileId
     * @throws Exception
     * @return {}
     */
    async deleteFile(fileId) {
        let path = '/storage/files/{fileId}'.replace(new RegExp('{fileId}', 'g'), fileId);
        
        return await this.client.call('delete', path, {'content-type': 'application/json'},
            {
            });
    }

    /**
     * Get File for Download
     *
     * /docs/references/storage/get-file-download.md
     *
     * @param string fileId
     * @throws Exception
     * @return {}
     */
    async getFileDownload(fileId) {
        let path = '/storage/files/{fileId}/download'.replace(new RegExp('{fileId}', 'g'), fileId);
        
        return await this.client.call('get', path, {'content-type': 'application/json'},
            {
            });
    }

    /**
     * Get File Preview
     *
     * /docs/references/storage/get-file-preview.md
     *
     * @param string fileId
     * @param number width
     * @param number height
     * @param number quality
     * @param string background
     * @param string output
     * @throws Exception
     * @return {}
     */
    async getFilePreview(fileId, width = 0, height = 0, quality = 100, background = '', output = '') {
        let path = '/storage/files/{fileId}/preview'.replace(new RegExp('{fileId}', 'g'), fileId);
        
        return await this.client.call('get', path, {'content-type': 'application/json'},
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
     * /docs/references/storage/get-file-view.md
     *
     * @param string fileId
     * @param string as
     * @throws Exception
     * @return {}
     */
    async getFileView(fileId, as = '') {
        let path = '/storage/files/{fileId}/view'.replace(new RegExp('{fileId}', 'g'), fileId);
        
        return await this.client.call('get', path, {'content-type': 'application/json'},
            {
                'as': as
            });
    }
}

module.exports = Storage;