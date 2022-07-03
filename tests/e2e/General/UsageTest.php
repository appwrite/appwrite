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

    protected int $usersCount = 0;
    protected int $requestsCount = 0;

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

            if ($i < 5) {
                $userId = $res['body']['$id'];
                $res = $this->client->call(Client::METHOD_GET, '/users/' . $userId, $this->headers);
                $this->assertEquals($userId, $res['body']['$id']);
                $res = $this->client->call(Client::METHOD_DELETE, '/users/' . $userId, $this->headers);
                $this->assertEmpty($res['body']);
                $this->requestsCount += 2;
                $this->usersCount--;
            }

            $this->usersCount++;
            $this->requestsCount++;
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
        $this->assertEquals($this->usersCount, $res['users'][array_key_last($res['users'])]['value']);
        $this->assertEquals($this->requestsCount, $res['requests'][array_key_last($res['requests'])]['value']);

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
