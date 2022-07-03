<?php

use PHPUnit\Framework\TestCase;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;

class UsageTest extends Scope
{
    use ProjectCustom;
    use SideServer;

    protected array $headers = [];
    protected string $projectId;

    protected function setUp(): void
    {
        parent::setUp();
        $project = $this->getProject(true);
        $this->projectId = $project['$id'];
        $this->headers['x-appwrite-project'] = $project['$id'];
        $this->headers['x-appwrite-key'] = $project['apiKey'];
        $this->headers['content-type'] = 'application/json';
    }

    public function testUsersStats(): void
    {
        $usersCount = 0;
        $requestsCount = 0;
        for ($i = 0; $i < 10; $i++) {
            $email = uniqid() . 'user@usage.test';
            $password = 'password';
            $name = uniqid() . 'User';
            $res = $this->client->call(Client::METHOD_POST, '/users', $this->headers, [
                'userId' => 'unique()',
                'email' => $email,
                'password' => $password,
                'name' => $name,
            ]);
            $this->assertEquals($email, $res['body']['email']);
            $this->assertNotEmpty($res['body']['$id']);
            $usersCount++;
            $requestsCount++;

            if ($i < 5) {
                $userId = $res['body']['$id'];
                $res = $this->client->call(Client::METHOD_GET, '/users/' . $userId, $this->headers);
                $this->assertEquals($userId, $res['body']['$id']);
                $res = $this->client->call(Client::METHOD_DELETE, '/users/' . $userId, $this->headers);
                $this->assertEmpty($res['body']);
                $requestsCount += 2;
                $usersCount--;
            }

        }


        sleep(75);

        // console request
        $headers = [
            'origin' => 'http://localhost',
            'x-appwrite-project' => 'console',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
        ];

        $res = $this->client->call(Client::METHOD_GET, '/projects/' . $this->projectId . '/usage?range=30d', $headers);
        $res = $res['body'];

        $this->assertEquals(8, count($res));
        $this->assertEquals(30, count($res['requests']));
        $this->assertEquals(30, count($res['users']));
        $this->assertEquals($usersCount, $res['users'][array_key_last($res['users'])]['value']);
        $this->assertEquals($requestsCount, $res['requests'][array_key_last($res['requests'])]['value']);

        $res = $this->client->call(Client::METHOD_GET, '/users/usage?range=30d', array_merge($headers, [
            'x-appwrite-project' => $this->projectId,
            'x-appwrite-mode' => 'admin'
        ]));
        $res = $res['body'];
        $this->assertEquals(10, $res['usersCreate'][array_key_last($res['usersCreate'])]['value']);
        $this->assertEquals(5, $res['usersRead'][array_key_last($res['usersRead'])]['value']);
        $this->assertEquals(5, $res['usersDelete'][array_key_last($res['usersDelete'])]['value']);
    }

    public function testStorageStats() {
        $bucketId = '';
        $bucketsCount = 0;
        $requestsCount = 0;
        $storageTotal = 0;
        $bucketsCreate = 0;
        $bucketsDelete = 0;
        $bucketsRead = 0;
        $filesCount = 0;


        for ($i = 0; $i < 10; $i++) {
            $name = uniqid() . ' bucket';
            $res = $this->client->call(Client::METHOD_POST, '/storage/buckets', $this->headers, [
                'bucketId' => 'unique()',
                'name' => $name,
                'permission' => 'bucket'
            ]);
            $this->assertEquals($name, $res['body']['name']);
            $this->assertNotEmpty($res['body']['$id']);
            $bucketId = $res['body']['$id'];
            array_push($bucketIds, $bucketId);
            
            $bucketsCreate++;
            $bucketsCount++;
            $requestsCount++;

            if ($i < 5) {
                $res = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId, $this->headers);
                $this->assertEquals($bucketId, $res['body']['$id']);
                $bucketsRead++;

                $res = $this->client->call(Client::METHOD_DELETE, '/storage/buckets/' . $bucketId, $this->headers);
                $this->assertEmpty($res['body']);
                $bucketsDelete++;
                array_pop($bucketIds);
                
                $requestsCount += 2;
                $bucketsCount--;
            }

        }

        // upload some files
        for ($i = 0; $i < 10; $i++) {
            $res = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', $this->headers, [
                'fileId' => 'unique()',
                'file' => new CURLFile(realpath(__DIR__ . '/../../resources/logo.png', '', 'logo.png')),
            ]);

            $this->assertNotEmpty($res['body']['$id']);

            $storageTotal += $res['body']['size'];
            $filesCount++;
            $requestsCount++;
            
            
            if ($i < 5) {
                $res = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId, $this->headers);
                $this->assertEquals($bucketId, $res['body']['$id']);
                $res = $this->client->call(Client::METHOD_DELETE, '/storage/buckets/' . $bucketId, $this->headers);
                $this->assertEmpty($res['body']);
                array_pop($bucketIds);
                $requestsCount += 2;
                $filesCount--;
            }

        }
        
        sleep(75);

        // console request
        $headers = [
            'origin' => 'http://localhost',
            'x-appwrite-project' => 'console',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
        ];

        $res = $this->client->call(Client::METHOD_GET, '/projects/' . $this->projectId . '/usage?range=30d', $headers);
        $res = $res['body'];

        $this->assertEquals(8, count($res));
        $this->assertEquals(30, count($res['requests']));
        $this->assertEquals($requestsCount, $res['requests'][array_key_last($res['requests'])]['value']);

        $res = $this->client->call(Client::METHOD_GET, '/users/usage?range=30d', array_merge($headers, [
            'x-appwrite-project' => $this->projectId,
            'x-appwrite-mode' => 'admin'
        ]));
        $res = $res['body'];
        $this->assertEquals(10, $res['usersCreate'][array_key_last($res['usersCreate'])]['value']);
        $this->assertEquals(5, $res['usersRead'][array_key_last($res['usersRead'])]['value']);
        $this->assertEquals(5, $res['usersDelete'][array_key_last($res['usersDelete'])]['value']);
    }

    protected function tearDown(): void
    {
        $this->usersCount = 0;
        $this->requestsCount = 0;
        $this->projectId = '';
        $this->headers = [];
    }
}
