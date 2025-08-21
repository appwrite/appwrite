<?php

namespace Tests\E2E\Services\GraphQL;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;

class AvatarsTest extends Scope
{
    use ProjectCustom;
    use SideServer;
    use Base;

    public function testGetCreditCardIcon()
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::GET_CREDIT_CARD_ICON);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'code' => 'visa',
            ],
        ];

        $creditCardIcon = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertEquals(200, $creditCardIcon['headers']['status-code']);
        $this->assertNotEmpty($creditCardIcon['body']);
        $this->assertStringContainsString('image/', $creditCardIcon['headers']['content-type']);

        return $creditCardIcon['body'];
    }

    public function testGetBrowserIcon()
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::GET_BROWSER_ICON);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'code' => 'ff',
            ],
        ];

        $browserIcon = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertEquals(200, $browserIcon['headers']['status-code']);
        $this->assertNotEmpty($browserIcon['body']);
        $this->assertStringContainsString('image/', $browserIcon['headers']['content-type']);

        return $browserIcon['body'];
    }

    public function testGetCountryFlag()
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::GET_COUNTRY_FLAG);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'code' => 'us',
            ],
        ];

        $countryFlag = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertEquals(200, $countryFlag['headers']['status-code']);
        $this->assertNotEmpty($countryFlag['body']);
        $this->assertStringContainsString('image/', $countryFlag['headers']['content-type']);

        return $countryFlag['body'];
    }

    public function testGetImageFromURL()
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::GET_IMAGE_FROM_URL);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'url' => 'https://www.google.com/images/branding/googlelogo/2x/googlelogo_color_272x92dp.png',
            ],
        ];

        $image = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertEquals(200, $image['headers']['status-code']);
        $this->assertNotEmpty($image['body']);
        $this->assertStringContainsString('image/', $image['headers']['content-type']);

        return $image['body'];
    }

    public function testGetFavicon()
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::GET_FAVICON);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'url' => 'https://www.google.com/',
            ],
        ];

        $favicon = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertEquals(200, $favicon['headers']['status-code']);
        $this->assertNotEmpty($favicon['body']);
        $this->assertStringContainsString('image/', $favicon['headers']['content-type']);

        return $favicon['body'];
    }

    public function testGetQRCode()
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::GET_QRCODE);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'text' => 'https://www.google.com/',
            ],
        ];

        $qrCode = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertEquals(200, $qrCode['headers']['status-code']);
        $this->assertNotEmpty($qrCode['body']);
        $this->assertStringContainsString('image/', $qrCode['headers']['content-type']);

        return $qrCode['body'];
    }

    public function testGetInitials()
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::GET_USER_INITIALS);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'name' => 'John Doe',
            ],
        ];

        $initials = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertEquals(200, $initials['headers']['status-code']);
        $this->assertNotEmpty($initials['body']);
        $this->assertStringContainsString('image/', $initials['headers']['content-type']);

        return $initials['body'];
    }
}
