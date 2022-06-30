<?php

use PHPUnit\Framework\TestCase;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;

class UsageTest extends Scope {
    use ProjectCustom;
    use SideServer;

    protected array $headers = [];
    protected array $project;
    
    protected int $usersCount = 0;
    protected int $requestsCount = 0;

    protected function setUp(): void {
        $this->project = $this->getProject(true);
        $this->headers['x-appwrite-project'] = $this->project['$id'];
        $this->headers['x-appwrite-key'] = $this->project['apiKey'];
        $this->headers['content-type'] = 'application/json';

    }

    public function testUsersStats(): void
    {
        
        for($i = 0; $i<10; $i++) {
            $email = uniqid() . 'user@usage.test';
            $password = 'password';
            $name = uniqid() . 'User';
            $user = $this->client->call(Client::METHOD_POST, '/users', $this->headers, [
                'userId' => 'unique()',
                'email' => $email,
                'password' => $password,
                'name' => $name,
            ]);
            $this->usersCount++;
            $this->requestsCount++;
        }
    }

}