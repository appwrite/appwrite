<?php

namespace Tests\E2E\Scopes;

use Tests\E2E\Client;
use Utopia\Database\DateTime;
use Utopia\Database\ID;
use Utopia\Database\Permission;
use Utopia\Database\Role;

trait VideoCustom
{
    use ProjectCustom;

    /**
     * @var array
     */
    protected static $bucket = [];
    protected static $video = [];
    protected static $videoWithUserPerm = [];
    protected static $subtitle = [];

    /**
     * @return array
     */
    public function getBucket(): array
    {
        if (!empty(self::$bucket)) {
            return self::$bucket;
        }

        $_bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'bucketId' => 'unique()',
            'name' => 'Test Bucket 2',
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        self::$bucket = [
            '$id' => $_bucket['body']['$id'],
        ];

        return self::$bucket;
    }

    /**
     * @return array
     */
    public function getVideo(): array
    {

        if (!empty(self::$video)) {
            return self::$video;
        }

        $source = __DIR__ . "/../../resources/disk-a/video-srt.mp4";
        $totalSize = \filesize($source);
        $chunkSize = 5 * 1024 * 1024;
        $handle = @fopen($source, "rb");
        $fileId = 'unique()';
        $mimeType = mime_content_type($source);
        $counter = 0;
        $size = filesize($source);
        $headers = [
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id']
        ];
        $id = '';

        while (!feof($handle)) {
            $curlFile = new \CURLFile('data:' . $mimeType . ';base64,' . base64_encode(@fread($handle, $chunkSize)), $mimeType, 'video-srt.mp4');
            $headers['content-range'] = 'bytes ' . ($counter * $chunkSize) . '-' . min(((($counter * $chunkSize) + $chunkSize) - 1), $size) . '/' . $size;

            if (!empty($id)) {
                $headers['x-appwrite-id'] = $id;
            }

            $_file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $this->getBucket()['$id'] . '/files', array_merge($headers, $this->getHeaders()), [
                'fileId' => $fileId,
                'file' => $curlFile,

            ]);
            $counter++;
            $id = $_file['body']['$id'];
        }
        @fclose($handle);

        self::$video = [
            '$id' => $_file['body']['$id'],
        ];

        return self::$video;
    }

    /**
     * @return array
     */
    public function getVideoWithUserPermissions(): array
    {

        if (!empty(self::$videoWithUserPerm)) {
            return self::$videoWithUserPerm;
        }

        $source = __DIR__ . "/../../resources/disk-a/video-srt.mp4";
        $totalSize = \filesize($source);
        $chunkSize = 5 * 1024 * 1024;
        $handle = @fopen($source, "rb");
        $fileId = 'unique()';
        $mimeType = mime_content_type($source);
        $counter = 0;
        $size = filesize($source);
        $headers = [
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id']
        ];
        $id = '';

        while (!feof($handle)) {
            $curlFile = new \CURLFile('data:' . $mimeType . ';base64,' . base64_encode(@fread($handle, $chunkSize)), $mimeType, 'video-srt.mp4');
            $headers['content-range'] = 'bytes ' . ($counter * $chunkSize) . '-' . min(((($counter * $chunkSize) + $chunkSize) - 1), $size) . '/' . $size;

            if (!empty($id)) {
                $headers['x-appwrite-id'] = $id;
            }

            $_file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $this->getBucket()['$id'] . '/files', array_merge($headers, $this->getHeaders()), [
                'fileId' => $fileId,
                'file' => $curlFile,
                'permissions' => [
                    Permission::read(Role::team(ID::custom($team1['$id']))),
                    Permission::read(Role::team(ID::custom($team2['$id']))),
                    Permission::update(Role::team(ID::custom($team1['$id']))),
                    Permission::delete(Role::team(ID::custom($team1['$id']))),
                ],

            ]);
            $counter++;
            $id = $_file['body']['$id'];
        }
        @fclose($handle);

        self::$videoWithUserPerm = [
            '$id' => $_file['body']['$id'],
        ];

        return self::$videoWithUserPerm;
    }


    /**
     * @return array
     */
    public function getSubtitle(): array
    {

        if (!empty(self::$subtitle)) {
            return self::$subtitle;
        }

        $res = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $this->getBucket()['$id'] . '/files', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'fileId' => 'unique()',
            'file' => new \CURLFile(realpath(__DIR__ . '/../../resources/disk-a//../../resources/disk-a/video-srt.srt'), 'text/plain', 'video-srt.srt'),
            'read' => ['role:all'],
            'write' => ['role:all'],
        ]);


        self::$subtitle = [
            '$id' => $res['body']['$id'],
        ];

        return self::$subtitle;
    }
}
