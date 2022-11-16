<?php

namespace Tests\Benchmarks\Users;

use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\ParamProviders;
use Tests\Benchmarks\Scope;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\ID;

class UserCustomServerBench extends Scope
{
    use ProjectCustom;
    use SideServer;

    protected static string $userId;

    public function benchUserCreate()
    {
        $id = ID::unique();

        $this->client->call(Client::METHOD_POST, '/users', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'userId' => $id,
            'email' => 'test' . $id . '@example.com',
            'password' => 'password',
        ]);
    }

    #[ParamProviders(['provideCounts'])]
    #[BeforeMethods(['createUsers'])]
    public function benchUserReadList(array $params)
    {
        $this->client->call(Client::METHOD_GET, '/users', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => ['limit(' . $params['users'] . ')'],
        ]);
    }

    #[BeforeMethods(['createUsers'])]
    public function benchUserRead()
    {
        $this->client->call(Client::METHOD_GET, '/users/' . static::$userId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
    }

    #[BeforeMethods(['createUsers'])]
    public function benchUserUpdate()
    {
        $this->client->call(Client::METHOD_PUT, '/users/' . static::$userId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'New Name',
        ]);
    }

    public function createUsers(array $params = [])
    {
        $count = $params['documents'] ?? 1;

        for ($i = 0; $i < $count; $i++) {
            $id = ID::unique();

            $response = $this->client->call(Client::METHOD_POST, '/users', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()), [
                'userId' => $id,
                'email' => 'test' . $id . '@example.com',
                'password' => 'password',
            ]);

            static::$userId = $response['body']['$id'];
        }
    }

    public function provideCounts(): array
    {
        return [
            '1 User' => ['users' => 1],
            '10 Users' => ['users' => 10],
            '100 Users' => ['users' => 100],
        ];
    }
}
