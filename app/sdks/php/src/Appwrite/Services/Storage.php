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
     * Get a list of all the user files. You can use the query params to filter
     * your results. On admin mode, this endpoint will return a list of all of the
     * project files. [Learn more about different API modes](/docs/modes).
     *
     * @param string  $search
     * @param int  $limit
     * @param int  $offset
     * @param string  $orderType
     * @throws Exception
     * @return array
     */
    public function listFiles(string $search = '', int $limit = 25, int $offset = 0, string $orderType = 'ASC'):array
    {
        $path   = str_replace([], [], '/storage/files');
        $params = [];

        $params['search'] = $search;
        $params['limit'] = $limit;
        $params['offset'] = $offset;
        $params['orderType'] = $orderType;

        return $this->client->call(Client::METHOD_GET, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Create File
     *
     * Create a new file. The user who creates the file will automatically be
     * assigned to read and write access unless he has passed custom values for
     * read and write arguments.
     *
     * @param \CurlFile  $files
     * @param array  $read
     * @param array  $write
     * @param string  $folderId
     * @throws Exception
     * @return array
     */
    public function createFile(\CurlFile $files, array $read, array $write, string $folderId = ''):array
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
     * Get file by its unique ID. This endpoint response returns a JSON object
     * with the file metadata.
     *
     * @param string  $fileId
     * @throws Exception
     * @return array
     */
    public function getFile(string $fileId):array
    {
        $path   = str_replace(['{fileId}'], [$fileId], '/storage/files/{fileId}');
        $params = [];


        return $this->client->call(Client::METHOD_GET, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Update File
     *
     * Update file by its unique ID. Only users with write permissions have access
     * to update this resource.
     *
     * @param string  $fileId
     * @param array  $read
     * @param array  $write
     * @param string  $folderId
     * @throws Exception
     * @return array
     */
    public function updateFile(string $fileId, array $read, array $write, string $folderId = ''):array
    {
        $path   = str_replace(['{fileId}'], [$fileId], '/storage/files/{fileId}');
        $params = [];

        $params['read'] = $read;
        $params['write'] = $write;
        $params['folderId'] = $folderId;

        return $this->client->call(Client::METHOD_PUT, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Delete File
     *
     * Delete a file by its unique ID. Only users with write permissions have
     * access to delete this resource.
     *
     * @param string  $fileId
     * @throws Exception
     * @return array
     */
    public function deleteFile(string $fileId):array
    {
        $path   = str_replace(['{fileId}'], [$fileId], '/storage/files/{fileId}');
        $params = [];


        return $this->client->call(Client::METHOD_DELETE, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Get File for Download
     *
     * Get file content by its unique ID. The endpoint response return with a
     * 'Content-Disposition: attachment' header that tells the browser to start
     * downloading the file to user downloads directory.
     *
     * @param string  $fileId
     * @throws Exception
     * @return array
     */
    public function getFileDownload(string $fileId):array
    {
        $path   = str_replace(['{fileId}'], [$fileId], '/storage/files/{fileId}/download');
        $params = [];


        return $this->client->call(Client::METHOD_GET, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Get File Preview
     *
     * Get file preview image. Currently, this method supports preview for image
     * files (jpg, png, and gif), other supported formats, like pdf, docs, slides,
     * and spreadsheets will return file icon image. You can also pass query
     * string arguments for cutting and resizing your preview image.
     *
     * @param string  $fileId
     * @param int  $width
     * @param int  $height
     * @param int  $quality
     * @param string  $background
     * @param string  $output
     * @throws Exception
     * @return array
     */
    public function getFilePreview(string $fileId, int $width = 0, int $height = 0, int $quality = 100, string $background = '', string $output = ''):array
    {
        $path   = str_replace(['{fileId}'], [$fileId], '/storage/files/{fileId}/preview');
        $params = [];

        $params['width'] = $width;
        $params['height'] = $height;
        $params['quality'] = $quality;
        $params['background'] = $background;
        $params['output'] = $output;

        return $this->client->call(Client::METHOD_GET, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Get File for View
     *
     * Get file content by its unique ID. This endpoint is similar to the download
     * method but returns with no  'Content-Disposition: attachment' header.
     *
     * @param string  $fileId
     * @param string  $as
     * @throws Exception
     * @return array
     */
    public function getFileView(string $fileId, string $as = ''):array
    {
        $path   = str_replace(['{fileId}'], [$fileId], '/storage/files/{fileId}/view');
        $params = [];

        $params['as'] = $as;

        return $this->client->call(Client::METHOD_GET, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

}