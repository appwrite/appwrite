<?php

namespace Tests\E2E\Services\Teams;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;

class TeamsCustomClientTest extends Scope
{
    use TeamsBase;
    use TeamsBaseClient;
    use ProjectCustom;
    use SideClient;

    /**
     * @depends testUpdateTeamMembership
     */
    public function testTeamsInviteHTMLInjection($data): array
    {
        $teamUid = $data['teamUid'] ?? '';
        $email = uniqid() . 'friend@localhost.test';
        $name = 'Friend User';
        $password = 'password';

        // Create a user account before we create a invite so we can check if the user has permissions when it shouldn't
        $user = $this->client->call(Client::METHOD_POST, '/account', [
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console'], [
                'userId' => 'unique()',
                'email' => $email,
                'password' => $password,
                'name' => $name,
            ], false);

        $this->assertEquals(201, $user['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/teams/' . $teamUid . '/memberships', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'email' => $email,
            'name' => $name,
            'roles' => ['admin', 'editor'],
            'url' => 'http://localhost:5000/join-us\"></a><h1>INJECTED</h1>'
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        $email = $this->getLastEmail();
        $encoded = 'http://localhost:5000/join-us\&quot;&gt;&lt;/a&gt;&lt;h1&gt;INJECTED&lt;/h1&gt;?';

        $this->assertStringNotContainsString('<h1>INJECTED</h1>', $email['html']);
        $this->assertStringContainsString($encoded, $email['html']);
        $this->assertStringContainsString($encoded, $email['text']);

        $response = $this->client->call(Client::METHOD_DELETE, '/teams/' . $teamUid . '/memberships/'.$response['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
        $this->assertEquals(204, $response['headers']['status-code']);


        return $data;
    }
}
