<?php

namespace Appwrite\Services;

use Exception;
use Appwrite\Client;
use Appwrite\Service;

class Storage extends Service
{
    /**
     * List Files
     *
     * /docs/references/storage/list-files.md
     *
     * @param string $search
     * @param integer $limit
     * @param integer $offset
     * @param string $orderType
     * @throws Exception
     * @return array
     */
    public function listFiles($search = '', $limit = 25, $offset = 0, $orderType = 'ASC')
    {
        $path   = str_replace([], [], '/storage/files');
        $params = [];

        $params['search'] = $search;
        $params['limit'] = $limit;
        $params['offset'] = $offset;
        $params['orderType'] = $orderType;

        return $this->client->call(Client::METHOD_GET, $path, [
        ], $params);
    }

    /**
     * Create File
     *
     * /docs/references/storage/create-file.md
     *
     * @param \CurlFile $files
     * @param array $read
     * @param array $write
     * @param string $folderId
     * @throws Exception
     * @return array
     */
    public function createFile($files, $read = [], $write = [], $folderId = '')
    {
        $path   = str_replace([], [], '/storage/files');
        $params = [];

        $params['files'] = $files;
        $params['read'] = $read;
        $params['write'] = $write;
        $params['folderId'] = $folderId;

        return $this->client->call(Client::METHOD_POST, $path, [
            'content-type' => 'multipart/form-data',
        ], $params);
    }

    /**
     * Get File
     *
     * /docs/references/storage/get-file.md
     *
     * @param string $fileId
     * @throws Exception
     * @return array
     */
    public function getFile($fileId)
    {
        $path   = str_replace(['{fileId}'], [$fileId], '/storage/files/{fileId}');
        $params = [];


        return $this->client->call(Client::METHOD_GET, $path, [
        ], $params);
    }

    /**
     * Update File
     *
     * /docs/references/storage/update-file.md
     *
     * @param string $fileId
     * @param array $read
     * @param array $write
     * @param string $folderId
     * @throws Exception
     * @return array
     */
    public function updateFile($fileId, $read = [], $write = [], $folderId = '')
    {
        $path   = str_replace(['{fileId}'], [$fileId], '/storage/files/{fileId}');
        $params = [];

        $params['read'] = $read;
        $params['write'] = $write;
        $params['folderId'] = $folderId;

        return $this->client->call(Client::METHOD_PUT, $path, [
        ], $params);
    }

    /**
     * Delete File
     *
     * /docs/references/storage/delete-file.md
     *
     * @param string $fileId
     * @throws Exception
     * @return array
     */
    public function deleteFile($fileId)
    {
        $path   = str_replace(['{fileId}'], [$fileId], '/storage/files/{fileId}');
        $params = [];


        return $this->client->call(Client::METHOD_DELETE, $path, [
        ], $params);
    }

    /**
     * Get File for Download
     *
     * /docs/references/storage/get-file-download.md
     *
     * @param string $fileId
     * @throws Exception
     * @return array
     */
    public function getFileDownload($fileId)
    {
        $path   = str_replace(['{fileId}'], [$fileId], '/storage/files/{fileId}/download');
        $params = [];


        return $this->client->call(Client::METHOD_GET, $path, [
        ], $params);
    }

    /**
     * Get File Preview
     *
     * /docs/references/storage/get-file-preview.md
     *
     * @param string $fileId
     * @param integer $width
     * @param integer $height
     * @param integer $quality
     * @param string $background
     * @param string $output
     * @throws Exception
     * @return array
     */
    public function getFilePreview($fileId, $width = 0, $height = 0, $quality = 100, $background = '', $output = '')
    {
        $path   = str_replace(['{fileId}'], [$fileId], '/storage/files/{fileId}/preview');
        $params = [];

        $params['width'] = $width;
        $params['height'] = $height;
        $params['quality'] = $quality;
        $params['background'] = $background;
        $params['output'] = $output;

        return $this->client->call(Client::METHOD_GET, $path, [
        ], $params);
    }

    /**
     * Get File for View
     *
     * /docs/references/storage/get-file-view.md
     *
     * @param string $fileId
     * @param string $as
     * @throws Exception
     * @return array
     */
    public function getFileView($fileId, $as = '')
    {
        $path   = str_replace(['{fileId}'], [$fileId], '/storage/files/{fileId}/view');
        $params = [];

        $params['as'] = $as;

        return $this->client->call(Client::METHOD_GET, $path, [
        ], $params);
    }

}