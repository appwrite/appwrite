<?php

namespace Tests\E2E\Scopes;

use Appwrite\Tests\Retryable;
use Tests\E2E\Client;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Helpers\ID;

abstract class Scope extends TestCase
{
    use Retryable;

    protected ?Client $client = null;
    protected string $endpoint = 'http://localhost/v1';

    protected function setUp(): void
    {
        $this->client = new Client();

        $this->client
            ->setEndpoint($this->endpoint)
        ;
    }

    protected function tearDown(): void
    {
        $this->client = null;
    }

    protected function getLastEmail(): array
    {
        sleep(3);

        $emails = json_decode(file_get_contents('http://maildev:1080/email'), true);

        if ($emails && is_array($emails)) {
            return end($emails);
        }

        return [];
    }

    protected function getLastRequest(): array
    {
        sleep(2);

        $resquest = json_decode(file_get_contents('http://request-catcher:5000/__last_request__'), true);
        $resquest['data'] = json_decode($resquest['data'], true);

        return $resquest;
    }

    /**
     * @return array
     */
    abstract public function getHeaders(): array;

    /**
     * @return array
     */
    abstract public function getProject(): array;

    /**
     * @var array
     */
    protected static $root = [];

    /**
     * @return array
     */
    public function getRoot(): array
    {
        if ((self::$root)) {
            return self::$root;
        }

        $email = uniqid() . 'user@localhost.test';
        $password = 'password';
        $name = 'User Name';

        $root = $this->client->call(Client::METHOD_POST, '/account', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
        ], [
            'userId' => ID::unique(),
            'email' => $email,
            'password' => $password,
            'name' => $name,
        ]);

        $this->assertEquals(201, $root['headers']['status-code']);

        $session = $this->client->call(Client::METHOD_POST, '/account/sessions/email', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
        ], [
            'email' => $email,
            'password' => $password,
        ]);

        $session = $this->client->parseCookie((string)$session['headers']['set-cookie'])['a_session_console'];

        self::$root = [
            '$id' => ID::custom($root['body']['$id']),
            'name' => $root['body']['name'],
            'email' => $root['body']['email'],
            'session' => $session,
        ];

        return self::$root;
    }

    /**
     * @var array
     */
    protected static $user = [];

    /**
     * @return array
     */
    public function getUser(): array
    {
        if (isset(self::$user[$this->getProject()['$id']])) {
            return self::$user[$this->getProject()['$id']];
        }

        $email = uniqid() . 'user@localhost.test';
        $password = 'password';
        $name = 'User Name';

        $user = $this->client->call(Client::METHOD_POST, '/account', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'userId' => ID::unique(),
            'email' => $email,
            'password' => $password,
            'name' => $name,
        ]);

        $this->assertEquals(201, $user['headers']['status-code']);

        $session = $this->client->call(Client::METHOD_POST, '/account/sessions/email', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'email' => $email,
            'password' => $password,
        ]);

        $session = $this->client->parseCookie((string)$session['headers']['set-cookie'])['a_session_' . $this->getProject()['$id']];

        self::$user[$this->getProject()['$id']] = [
            '$id' => ID::custom($user['body']['$id']),
            'name' => $user['body']['name'],
            'email' => $user['body']['email'],
            'session' => $session,
        ];

        return self::$user[$this->getProject()['$id']];
    }
}
