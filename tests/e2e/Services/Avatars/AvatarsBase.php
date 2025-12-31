<?php

namespace Tests\E2E\Services\Avatars;

use Tests\E2E\Client;

trait AvatarsBase
{
    public function testGetCreditCard(): array
    {
        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/avatars/credit-cards/visa', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('image/png', $response['headers']['content-type']);
        $this->assertNotEmpty($response['body']);

        $response = $this->client->call(Client::METHOD_GET, '/avatars/credit-cards/visa', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'width' => 200,
            'height' => 200,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('image/png', $response['headers']['content-type']);
        $this->assertNotEmpty($response['body']);

        $response = $this->client->call(Client::METHOD_GET, '/avatars/credit-cards/visa', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'width' => 300,
            'height' => 300,
            'quality' => 30,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('image/png', $response['headers']['content-type']);
        $this->assertNotEmpty($response['body']);

        /**
         * Test for FAILURE
         */

        $response = $this->client->call(Client::METHOD_GET, '/avatars/credit-cards/unknown', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'width' => 300,
            'height' => 300,
            'quality' => 30,
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/avatars/credit-cards/visa', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'width' => 2001,
            'height' => 300,
            'quality' => 30,
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        return [];
    }

    public function testGetBrowser(): array
    {
        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/avatars/browsers/ch', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('image/png', $response['headers']['content-type']);
        $this->assertNotEmpty($response['body']);

        $response = $this->client->call(Client::METHOD_GET, '/avatars/browsers/ch', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'width' => 200,
            'height' => 200,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('image/png', $response['headers']['content-type']);
        $this->assertNotEmpty($response['body']);

        $response = $this->client->call(Client::METHOD_GET, '/avatars/browsers/ch', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'width' => 300,
            'height' => 300,
            'quality' => 30,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('image/png', $response['headers']['content-type']);
        $this->assertNotEmpty($response['body']);

        /**
         * Test for FAILURE
         */

        $response = $this->client->call(Client::METHOD_GET, '/avatars/browsers/unknown', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'width' => 300,
            'height' => 300,
            'quality' => 30,
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/avatars/browsers/ch', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'width' => 2001,
            'height' => 300,
            'quality' => 30,
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        return [];
    }

    public function testGetFlag(): array
    {
        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/avatars/flags/us', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('image/png', $response['headers']['content-type']);
        $this->assertNotEmpty($response['body']);

        $response = $this->client->call(Client::METHOD_GET, '/avatars/flags/us', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'width' => 200,
            'height' => 200,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('image/png', $response['headers']['content-type']);
        $this->assertNotEmpty($response['body']);

        $response = $this->client->call(Client::METHOD_GET, '/avatars/flags/us', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'width' => 300,
            'height' => 300,
            'quality' => 30,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('image/png', $response['headers']['content-type']);
        $this->assertNotEmpty($response['body']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_GET, '/avatars/flags/unknown', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'width' => 300,
            'height' => 300,
            'quality' => 30,
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/avatars/flags/us', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'width' => 2001,
            'height' => 300,
            'quality' => 30,
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        return [];
    }

    public function testGetImage(): array
    {
        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/avatars/image', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://appwrite.io/images/open-graph/website.png',
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('image/png', $response['headers']['content-type']);
        $this->assertNotEmpty($response['body']);

        $response = $this->client->call(Client::METHOD_GET, '/avatars/image', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://appwrite.io/images/open-graph/website.png',
            'width' => 200,
            'height' => 200,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('image/png', $response['headers']['content-type']);
        $this->assertNotEmpty($response['body']);

        $response = $this->client->call(Client::METHOD_GET, '/avatars/image', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://appwrite.io/images/open-graph/website.png',
            'width' => 300,
            'height' => 300,
            'quality' => 30,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('image/png', $response['headers']['content-type']);
        $this->assertNotEmpty($response['body']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_GET, '/avatars/image', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://appwrite.io/images/unknown.png',
            'width' => 300,
            'height' => 300,
            'quality' => 30,
        ]);

        $this->assertEquals(404, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/avatars/image', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://appwrite.io/images/open-graph/website.png',
            'width' => 2001,
            'height' => 300,
            'quality' => 30,
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/avatars/image', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'invalid://appwrite.io/images/apple.png'
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        // TODO Add test for non-image file (PDF, WORD)

        return [];
    }

    public function testGetFavicon(): array
    {
        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/avatars/favicon', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://github.com/',
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('image/svg+xml', $response['headers']['content-type']);
        $this->assertNotEmpty($response['body']);

        $response = $this->client->call(Client::METHOD_GET, '/avatars/favicon', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://appwrite.io/',
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('image/svg+xml', $response['headers']['content-type']);
        $this->assertNotEmpty($response['body']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_GET, '/avatars/favicon', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'unknown-address',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/avatars/favicon', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'http://unknown-address.test',
        ]);

        $this->assertEquals(404, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/avatars/favicon', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'http://localhost',
        ]);

        $this->assertEquals(404, $response['headers']['status-code']);

        return [];
    }

    public function testGetQR(): array
    {
        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/avatars/qr', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'text' => 'url:https://appwrite.io/',
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('image/png', $response['headers']['content-type']);
        $this->assertNotEmpty($response['body']);

        $image = new \Imagick();
        $image->readImageBlob($response['body']);
        $this->assertEquals(400, $image->getImageWidth());
        $this->assertEquals(400, $image->getImageHeight());
        $this->assertEquals('PNG', $image->getImageFormat());
        $this->assertSamePixels(__DIR__ . '/../../../resources/qr/qr-default.png', $response['body']);

        $response = $this->client->call(Client::METHOD_GET, '/avatars/qr', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'text' => 'url:https://appwrite.io/',
            'size' => 200,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('image/png', $response['headers']['content-type']);
        $this->assertNotEmpty($response['body']);

        $image = new \Imagick();
        $image->readImageBlob($response['body']);
        $this->assertEquals(200, $image->getImageWidth());
        $this->assertEquals(200, $image->getImageHeight());
        $this->assertEquals('PNG', $image->getImageFormat());
        $this->assertSamePixels(__DIR__ . '/../../../resources/qr/qr-size-200.png', $response['body']);

        $response = $this->client->call(Client::METHOD_GET, '/avatars/qr', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'text' => 'url:https://appwrite.io/',
            'size' => 200,
            'margin' => 10,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('image/png', $response['headers']['content-type']);
        $this->assertNotEmpty($response['body']);

        $image = new \Imagick();
        $image->readImageBlob($response['body']);
        $this->assertEquals(200, $image->getImageWidth());
        $this->assertEquals(200, $image->getImageHeight());
        $this->assertEquals('PNG', $image->getImageFormat());
        $this->assertSamePixels(__DIR__ . '/../../../resources/qr/qr-size-200-margin-10.png', $response['body']);

        $response = $this->client->call(Client::METHOD_GET, '/avatars/qr', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'text' => 'url:https://appwrite.io/',
            'size' => 200,
            'margin' => 10,
            'download' => 1,
        ]);

        $image = new \Imagick();
        $image->readImageBlob($response['body']);
        $this->assertEquals(200, $image->getImageWidth());
        $this->assertEquals(200, $image->getImageHeight());
        $this->assertEquals('PNG', $image->getImageFormat());
        $this->assertSamePixels(__DIR__ . '/../../../resources/qr/qr-size-200-margin-10.png', $response['body']);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('attachment; filename="qr.png"', $response['headers']['content-disposition']);
        $this->assertEquals('image/png', $response['headers']['content-type']);
        $this->assertNotEmpty($response['body']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_GET, '/avatars/qr', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'text' => 'url:https://appwrite.io/',
            'size' => 1001,
            'margin' => 10,
            'download' => 1,
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/avatars/qr', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'text' => 'url:https://appwrite.io/',
            'size' => 400,
            'margin' => 11,
            'download' => 1,
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/avatars/qr', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'text' => 'url:https://appwrite.io/',
            'size' => 400,
            'margin' => 10,
            'download' => 2,
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        return [];
    }


    public function testGetInitials()
    {
        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/avatars/initials', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('image/png', $response['headers']['content-type']);
        $this->assertNotEmpty($response['body']);

        $response = $this->client->call(Client::METHOD_GET, '/avatars/initials', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'width' => 200,
            'height' => 200,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('image/png', $response['headers']['content-type']);
        $this->assertNotEmpty($response['body']);

        $response = $this->client->call(Client::METHOD_GET, '/avatars/initials', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'name' => 'W W',
            'width' => 200,
            'height' => 200,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('image/png', $response['headers']['content-type']);
        $this->assertNotEmpty($response['body']);

        $response = $this->client->call(Client::METHOD_GET, '/avatars/initials', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'name' => 'W W',
            'width' => 200,
            'height' => 200,
            'background' => '000000',
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('image/png', $response['headers']['content-type']);
        $this->assertNotEmpty($response['body']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_GET, '/avatars/initials', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'name' => 'W W',
            'width' => 200000,
            'height' => 200,
            'background' => '000000',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);
    }

    public function testInitialImage()
    {
        $response = $this->client->call(Client::METHOD_GET, '/avatars/initials', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'name' => 'W W',
            'width' => 200,
            'height' => 200,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('image/png', $response['headers']['content-type']);
        $this->assertNotEmpty($response['body']);

        $image = new \Imagick();
        $image->readImageBlob($response['body']);
        $original = new \Imagick(__DIR__ . '/../../../resources/initials.png');

        $this->assertEquals($image->getImageWidth(), $original->getImageWidth());
        $this->assertEquals($image->getImageHeight(), $original->getImageHeight());
        $this->assertEquals('PNG', $image->getImageFormat());
        $this->assertEquals(strlen(\file_get_contents(__DIR__ . '/../../../resources/initials.png')), strlen($response['body']));
    }

    public function testSpecialCharsInitalImage()
    {
        $response = $this->client->call(Client::METHOD_GET, '/avatars/initials', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'name' => 'W (Hello) W',
            'width' => 200,
            'height' => 200,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('image/png', $response['headers']['content-type']);
        $this->assertNotEmpty($response['body']);

        $image = new \Imagick();
        $image->readImageBlob($response['body']);
        $original = new \Imagick(__DIR__ . '/../../../resources/initials.png');

        $this->assertEquals($image->getImageWidth(), $original->getImageWidth());
        $this->assertEquals($image->getImageHeight(), $original->getImageHeight());
        $this->assertEquals('PNG', $image->getImageFormat());
        $this->assertEquals(strlen(\file_get_contents(__DIR__ . '/../../../resources/initials.png')), strlen($response['body']));
    }

    public function testGetScreenshot(): array
    {
        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://appwrite.io?x=' . time() . rand(1000, 9999),
            'width' => 800,
            'height' => 600,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('image/png', $response['headers']['content-type']);
        $this->assertNotEmpty($response['body']);
        $this->assertGreaterThan(100000, strlen($response['body']));

        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://appwrite.io?x=' . time() . rand(1000, 9999),
            'width' => 800,
            'height' => 600,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (compatible; AppwriteBot/1.0)',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'
            ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('image/png', $response['headers']['content-type']);
        $this->assertNotEmpty($response['body']);

        /**
         * Test for FAILURE - Invalid headers parameter types
         */

        // Test with string headers (should fail)
        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://appwrite.io?x=' . time() . rand(1000, 9999),
            'width' => 800,
            'height' => 600,
            'headers' => 'invalid-headers-string',
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);

        // Test with numeric headers (should fail)
        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://appwrite.io?x=' . time() . rand(1000, 9999),
            'width' => 800,
            'height' => 600,
            'headers' => 123,
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);

        // Test with boolean headers (should fail)
        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://appwrite.io?x=' . time() . rand(1000, 9999),
            'width' => 800,
            'height' => 600,
            'headers' => true,
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);

        // Test with null headers - framework converts null to empty array, so this passes
        // Skipping this test as null is converted to [] by the framework before validation

        // Test with regular array (indexed array) - should fail
        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://appwrite.io?x=' . time() . rand(1000, 9999),
            'width' => 800,
            'height' => 600,
            'headers' => ['value1', 'value2', 'value3'], // Indexed array
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);

        // Test with mixed array (some numeric keys) - Assoc validator allows this
        // Mixed arrays are considered associative by the Assoc validator
        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://appwrite.io?x=' . time() . rand(1000, 9999),
            'width' => 800,
            'height' => 600,
            'headers' => ['User-Agent' => 'MyApp', 'value2', 'Accept' => 'text/html'], // Mixed array
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);

        // Test with empty array (should pass - empty associative array)
        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://appwrite.io?x=' . time() . rand(1000, 9999),
            'width' => 800,
            'height' => 600,
            'headers' => [], // Empty associative array should pass
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);

        // Test with valid headers object (should pass)
        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://appwrite.io?x=' . time() . rand(1000, 9999),
            'width' => 800,
            'height' => 600,
            'headers' => [
                'User-Agent' => 'MyApp/1.0',
                'Accept' => 'text/html,application/xhtml+xml',
                'Accept-Language' => 'en-US,en;q=0.9'
            ],
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);

        // Test with headers containing special characters (should pass)
        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://appwrite.io?x=' . time() . rand(1000, 9999),
            'width' => 800,
            'height' => 600,
            'headers' => [
                'X-Custom-Header' => 'custom-value',
                'Authorization' => 'Bearer token123',
                'Content-Type' => 'application/json'
            ],
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);

        // Test with custom viewport width and height
        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://appwrite.io?x=' . time() . rand(1000, 9999),
            'viewportWidth' => 1920,
            'viewportHeight' => 1080,
            'width' => 800,
            'height' => 600,
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('image/png', $response['headers']['content-type']);
        $this->assertNotEmpty($response['body']);

        // Test with minimum valid viewport dimensions
        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://appwrite.io?x=' . time() . rand(1000, 9999),
            'viewportWidth' => 1,
            'viewportHeight' => 1,
            'width' => 800,
            'height' => 600,
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('image/png', $response['headers']['content-type']);
        $this->assertNotEmpty($response['body']);

        // Test with maximum valid viewport dimensions
        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://appwrite.io?x=' . time() . rand(1000, 9999),
            'viewportWidth' => 1920,
            'viewportHeight' => 1080,
            'width' => 800,
            'height' => 600,
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('image/png', $response['headers']['content-type']);
        $this->assertNotEmpty($response['body']);

        /**
         * Test for FAILURE - Invalid URL parameter
         */
        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'invalid-url',
            'width' => 800,
            'height' => 600,
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'ftp://example.com', // Non-HTTP/HTTPS URL
            'width' => 800,
            'height' => 600,
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);

        /**
         * Test for FAILURE - Invalid viewport parameters
         */
        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://appwrite.io?x=' . time() . rand(1000, 9999),
            'viewportWidth' => 0, // Too small
            'viewportHeight' => 720,
            'width' => 800,
            'height' => 600,
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://appwrite.io?x=' . time() . rand(1000, 9999),
            'viewportWidth' => 2000, // Too large
            'viewportHeight' => 720,
            'width' => 800,
            'height' => 600,
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://appwrite.io?x=' . time() . rand(1000, 9999),
            'viewportWidth' => 1280,
            'viewportHeight' => 0, // Too small
            'width' => 800,
            'height' => 600,
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://appwrite.io?x=' . time() . rand(1000, 9999),
            'viewportWidth' => 1280,
            'viewportHeight' => 2000, // Too large
            'width' => 800,
            'height' => 600,
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);

        /**
         * Test for FAILURE - Invalid width/height parameters
         */
        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://appwrite.io?x=' . time() . rand(1000, 9999),
            'width' => -1, // Invalid width (negative)
            'height' => 600,
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://appwrite.io?x=' . time() . rand(1000, 9999),
            'width' => 800,
            'height' => 3000, // Invalid height
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);

        /**
         * Test for FAILURE - Invalid sleep parameter
         */
        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://appwrite.io?x=' . time() . rand(1000, 9999),
            'width' => 800,
            'height' => 600,
            'sleep' => -1, // Negative sleep
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://appwrite.io?x=' . time() . rand(1000, 9999),
            'width' => 800,
            'height' => 600,
            'sleep' => 15, // Too large
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);

        /**
         * Test for FAILURE - Invalid quality parameter
         */
        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://appwrite.io?x=' . time() . rand(1000, 9999),
            'width' => 800,
            'height' => 600,
            'quality' => -2, // Too small
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://appwrite.io?x=' . time() . rand(1000, 9999),
            'width' => 800,
            'height' => 600,
            'quality' => 150, // Too large
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);

        /**
         * Test for FAILURE - Invalid output parameter
         */
        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://appwrite.io?x=' . time() . rand(1000, 9999),
            'width' => 800,
            'height' => 600,
            'output' => 'invalid-format',
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);

        /**
         * Test for SUCCESS - New screenshot parameters
         */
        // Test with theme parameter
        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://appwrite.io?x=' . time() . rand(1000, 9999),
            'width' => 800,
            'height' => 600,
            'theme' => 'dark',
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('image/png', $response['headers']['content-type']);
        $this->assertNotEmpty($response['body']);

        // Test with scale parameter
        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://appwrite.io?x=' . time() . rand(1000, 9999),
            'width' => 800,
            'height' => 600,
            'scale' => 2.0,
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('image/png', $response['headers']['content-type']);
        $this->assertNotEmpty($response['body']);

        // Test with userAgent parameter
        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://appwrite.io?x=' . time() . rand(1000, 9999),
            'width' => 800,
            'height' => 600,
            'userAgent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('image/png', $response['headers']['content-type']);
        $this->assertNotEmpty($response['body']);

        // Test with fullpage parameter
        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://appwrite.io?x=' . time() . rand(1000, 9999),
            'width' => 800,
            'height' => 600,
            'fullpage' => true,
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('image/png', $response['headers']['content-type']);
        $this->assertNotEmpty($response['body']);

        // Test with locale parameter
        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://appwrite.io?x=' . time() . rand(1000, 9999),
            'width' => 800,
            'height' => 600,
            'locale' => 'en-US',
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('image/png', $response['headers']['content-type']);
        $this->assertNotEmpty($response['body']);

        // Test with timezone parameter
        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://appwrite.io?x=' . time() . rand(1000, 9999),
            'width' => 800,
            'height' => 600,
            'timezone' => 'America/New_York',
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('image/png', $response['headers']['content-type']);
        $this->assertNotEmpty($response['body']);

        // Test with geolocation parameters
        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://appwrite.io?x=' . time() . rand(1000, 9999),
            'width' => 800,
            'height' => 600,
            'latitude' => 40.7128,
            'longitude' => -74.0060,
            'accuracy' => 100,
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('image/png', $response['headers']['content-type']);
        $this->assertNotEmpty($response['body']);

        // Test with touch parameter
        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://appwrite.io?x=' . time() . rand(1000, 9999),
            'width' => 800,
            'height' => 600,
            'touch' => true,
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('image/png', $response['headers']['content-type']);
        $this->assertNotEmpty($response['body']);

        // Test with permissions parameter
        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://appwrite.io?x=' . time() . rand(1000, 9999),
            'width' => 800,
            'height' => 600,
            'permissions' => [
                'geolocation',
                'camera',
                'microphone',
                'notifications'
            ],
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('image/png', $response['headers']['content-type']);
        $this->assertNotEmpty($response['body']);

        // Test with original dimensions (width=0, height=0)
        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://appwrite.io?x=' . time() . rand(1000, 9999),
            'width' => 0,
            'height' => 0,
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('image/png', $response['headers']['content-type']);
        $this->assertNotEmpty($response['body']);

        // Test with all new parameters combined
        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://appwrite.io?x=' . time() . rand(1000, 9999),
            'width' => 800,
            'height' => 600,
            'scale' => 1.5,
            'theme' => 'dark',
            'userAgent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36',
            'fullpage' => true,
            'locale' => 'en-GB',
            'timezone' => 'Europe/London',
            'latitude' => 51.5074,
            'longitude' => -0.1278,
            'accuracy' => 50,
            'touch' => true,
            'permissions' => [
                'geolocation',
                'camera',
                'microphone',
                'notifications',
                'clipboard-read',
                'clipboard-write'
            ],
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('image/png', $response['headers']['content-type']);
        $this->assertNotEmpty($response['body']);

        /**
         * Test for FAILURE - Invalid new parameters
         */

        // Test invalid theme parameter
        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://test' . time() . '.com',
            'width' => 800,
            'height' => 600,
            'theme' => 'invalid-theme',
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);

        // Test invalid scale parameter (too small)
        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://test' . time() . '.com',
            'width' => 800,
            'height' => 600,
            'scale' => 0.05, // Too small (min 0.1)
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);

        // Test invalid scale parameter (too large)
        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://test' . time() . '.com',
            'width' => 800,
            'height' => 600,
            'scale' => 5.0, // Too large (max 3.0)
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);

        // Test invalid userAgent parameter (too long)
        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://appwrite.io?x=' . time() . rand(1000, 9999),
            'width' => 800,
            'height' => 600,
            'userAgent' => str_repeat('A', 513), // Too long (max 512)
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);

        // Test invalid fullpage parameter (non-boolean)
        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://appwrite.io?x=' . time() . rand(1000, 9999),
            'width' => 800,
            'height' => 600,
            'fullpage' => 'invalid-boolean',
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);

        // Test invalid locale parameter (too long)
        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://appwrite.io?x=' . time() . rand(1000, 9999),
            'width' => 800,
            'height' => 600,
            'locale' => 'en-US-very-long-locale-string',
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);

        // Test invalid timezone parameter
        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://appwrite.io?x=' . time() . rand(1000, 9999),
            'width' => 800,
            'height' => 600,
            'timezone' => 'Invalid/Timezone',
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);

        // Test invalid latitude parameter (too high)
        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://appwrite.io?x=' . time() . rand(1000, 9999),
            'width' => 800,
            'height' => 600,
            'latitude' => 91, // Too high (max 90)
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);

        // Test invalid latitude parameter (too low)
        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://appwrite.io?x=' . time() . rand(1000, 9999),
            'width' => 800,
            'height' => 600,
            'latitude' => -91, // Too low (min -90)
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);

        // Test invalid longitude parameter (too high)
        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://appwrite.io?x=' . time() . rand(1000, 9999),
            'width' => 800,
            'height' => 600,
            'longitude' => 181, // Too high (max 180)
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);

        // Test invalid longitude parameter (too low)
        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://appwrite.io?x=' . time() . rand(1000, 9999),
            'width' => 800,
            'height' => 600,
            'longitude' => -181, // Too low (min -180)
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);

        // Test invalid accuracy parameter (too high)
        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://appwrite.io?x=' . time() . rand(1000, 9999),
            'width' => 800,
            'height' => 600,
            'accuracy' => 100001, // Too high (max 100000)
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);

        // Test invalid accuracy parameter (negative)
        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://appwrite.io?x=' . time() . rand(1000, 9999),
            'width' => 800,
            'height' => 600,
            'accuracy' => -1, // Negative (min 0)
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);

        // Test invalid touch parameter (non-boolean)
        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://appwrite.io?x=' . time() . rand(1000, 9999),
            'width' => 800,
            'height' => 600,
            'touch' => 'invalid-boolean',
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);

        // Test invalid permissions parameter (non-array)
        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://appwrite.io?x=' . time() . rand(1000, 9999),
            'width' => 800,
            'height' => 600,
            'permissions' => 'invalid-permissions-string',
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);

        // Test invalid permissions parameter (numeric array)
        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://appwrite.io?x=' . time() . rand(1000, 9999),
            'width' => 800,
            'height' => 600,
            'permissions' => ['geolocation', 'camera', 'microphone'], // This should pass as it's a valid array
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);

        // Test empty permissions array (should pass)
        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://appwrite.io?x=' . time() . rand(1000, 9999),
            'width' => 800,
            'height' => 600,
            'permissions' => [], // Empty array should pass
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);

        // Test invalid permission names (should fail)
        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://appwrite.io?x=' . time() . rand(1000, 9999),
            'width' => 800,
            'height' => 600,
            'permissions' => ['invalid-permission', 'another-invalid'],
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);

        // Test mixed valid and invalid permissions (should fail)
        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://appwrite.io?x=' . time() . rand(1000, 9999),
            'width' => 800,
            'height' => 600,
            'permissions' => ['geolocation', 'invalid-permission'],
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);

        // Test valid permission names (should pass)
        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://appwrite.io?x=' . time() . rand(1000, 9999),
            'width' => 800,
            'height' => 600,
            'permissions' => ['geolocation', 'camera', 'microphone', 'notifications'],
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);

        // Test advanced permission names (should pass)
        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://appwrite.io?x=' . time() . rand(1000, 9999),
            'width' => 800,
            'height' => 600,
            'permissions' => ['geolocation', 'camera', 'microphone'],
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);

        return [];
    }

    public function testGetScreenshotComparison(): array
    {
        /**
         * Test screenshot comparison with stable domain (example.com)
         * This test captures a screenshot of example.com and compares it
         * against a reference image to ensure consistent rendering.
         */
        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://example.com',
            'width' => 800,
            'height' => 600,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('image/png', $response['headers']['content-type']);
        $this->assertNotEmpty($response['body']);

        // Compare with reference screenshot
        $referencePath = \realpath(__DIR__ . '/../../../resources/avatars');
        $referenceScreenshot = $referencePath . '/screenshot-example-com.png';
        $this->assertFileExists($referenceScreenshot, 'Reference example.com screenshot not found');
        $this->assertSamePixels($referenceScreenshot, $response['body']);

        return [];
    }
}
