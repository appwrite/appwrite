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
        $query = $this->getQuery(self::$GET_CREDIT_CARD_ICON);
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

        $this->assertEquals(18767, \strlen($creditCardIcon['body']));

        return $creditCardIcon['body'];
    }

    public function testGetBrowserIcon()
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$GET_BROWSER_ICON);
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

        $this->assertEquals(11100, \strlen($browserIcon['body']));

        return $browserIcon['body'];
    }

    public function testGetCountryFlag()
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$GET_COUNTRY_FLAG);
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

        $this->assertEquals(7460, \strlen($countryFlag['body']));

        return $countryFlag['body'];
    }

    public function testGetImageFromURL()
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$GET_IMAGE_FROM_URL);
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

        $this->assertEquals(36036, \strlen($image['body']));

        return $image['body'];
    }

    public function testGetFavicon()
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$GET_FAVICON);
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

        $this->assertEquals(5430, \strlen($favicon['body']));

        return $favicon['body'];
    }

    public function testGetQRCode()
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$GET_QRCODE);
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

        $this->assertEquals(14771, \strlen($qrCode['body']));

        return $qrCode['body'];
    }

    public function testGetInitials()
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$GET_USER_INITIALS);
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

        $this->assertEquals(4959, \strlen($initials['body']));

        return $initials['body'];
    }
}
