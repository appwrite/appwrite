<?php

namespace Tests\Benchmarks\Storage;

use CURLFile;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\ParamProviders;
use Tests\Benchmarks\Scope;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Utopia\Database\ID;
use Utopia\Database\Permission;
use Utopia\Database\Role;

abstract class Base extends Scope
{
    use ProjectCustom;

    protected static string $bucketId;
    protected static string $fileId;

    #[BeforeMethods(['createBucket'])]
    public function benchFileCreate()
    {
        $this->client->call(Client::METHOD_POST, '/storage/buckets/' . static::$bucketId . '/files', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'fileId' => ID::unique(),
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::write(Role::user($this->getUser()['$id'])),
            ],
            'file' => new CURLFile(realpath(__DIR__ . '/../../resources/logo.png'), 'image/png', 'logo.png'),
        ]);
    }

    #[ParamProviders(['provideCounts'])]
    #[BeforeMethods(['createBucket', 'createFiles'])]
    public function benchFileReadList(array $params)
    {
        $this->client->call(Client::METHOD_GET, '/storage/buckets/' . static::$bucketId . '/files', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => ['limit(' . $params['files'] . ')'],
        ]);
    }

    #[BeforeMethods(['createBucket', 'createFiles'])]
    public function benchFileRead()
    {
        $this->client->call(Client::METHOD_GET, '/storage/buckets/' . static::$bucketId . '/files/' . static::$fileId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
    }

    #[BeforeMethods(['createBucket', 'createFiles'])]
    public function benchFileUpdate()
    {
        $this->client->call(Client::METHOD_PUT, '/storage/buckets/' . static::$bucketId . '/files/' . static::$fileId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Updated name',
            'permissions' => [],
        ]);
    }

    public function provideCounts(): array
    {
        return [
            '10 Files' => ['files' => 10],
            '100 Files' => ['files' => 100],
        ];
    }

    public function createBucket(array $params = [])
    {
        // Create bucket
        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'bucketId' => ID::unique(),
            'name' => 'Test Bucket',
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::write(Role::user($this->getUser()['$id'])),
            ],
            'fileSecurity' => true
        ]);
        static::$bucketId = $bucket['body']['$id'];
    }

    public function createFiles(array $params = [])
    {
        $count = $params['files'] ?? 1;

        // Create files
        for ($i = 0; $i < $count; $i++) {
            $response = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . static::$bucketId . '/files', [
                'content-type' => 'multipart/form-data',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey']
            ], [
                'fileId' => ID::unique(),
                'file' => new CURLFile(realpath(__DIR__ . '/../../resources/logo.png'), 'image/png', 'logo.png'),
            ]);

            static::$fileId = $response['body']['$id'];
        }
    }
}
