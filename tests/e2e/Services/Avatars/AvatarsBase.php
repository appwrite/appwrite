<?php

namespace Tests\E2E\Services\Avatars;

use Appwrite\Extend\Exception;
use Tests\E2E\Client;

trait AvatarsBase
{
    /**
     * Corner colour of every avatar Appwrite draws itself (#4F4F4F). The
     * initials square and the static fallback share one neutral surface, so
     * the corner says an image was drawn — never which provider drew it.
     */
    private const PHOTO_SURFACE_COLOR = ['r' => 79, 'g' => 79, 'b' => 79];

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
         * Wrapped in assertEventually to handle transient external URL failures
         */
        $this->assertEventually(function () {
            $response = $this->client->call(Client::METHOD_GET, '/avatars/image', [
                'x-appwrite-project' => $this->getProject()['$id'],
            ], [
                'url' => 'https://appwrite.io/images/open-graph/website.avif',
            ]);

            $this->assertEquals(200, $response['headers']['status-code']);
            $this->assertEquals('image/png', $response['headers']['content-type']);
            $this->assertNotEmpty($response['body']);
        }, 30_000, 2_000);

        $this->assertEventually(function () {
            $response = $this->client->call(Client::METHOD_GET, '/avatars/image', [
                'x-appwrite-project' => $this->getProject()['$id'],
            ], [
                'url' => 'https://appwrite.io/images/open-graph/website.avif',
                'width' => 200,
                'height' => 200,
            ]);

            $this->assertEquals(200, $response['headers']['status-code']);
            $this->assertEquals('image/png', $response['headers']['content-type']);
            $this->assertNotEmpty($response['body']);
        }, 30_000, 2_000);

        $this->assertEventually(function () {
            $response = $this->client->call(Client::METHOD_GET, '/avatars/image', [
                'x-appwrite-project' => $this->getProject()['$id'],
            ], [
                'url' => 'https://appwrite.io/images/open-graph/website.avif',
                'width' => 300,
                'height' => 300,
                'quality' => 30,
            ]);

            $this->assertEquals(200, $response['headers']['status-code']);
            $this->assertEquals('image/png', $response['headers']['content-type']);
            $this->assertNotEmpty($response['body']);
        }, 30_000, 2_000);

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

        $this->assertEventually(function () {
            $response = $this->client->call(Client::METHOD_GET, '/avatars/image', [
                'x-appwrite-project' => $this->getProject()['$id'],
            ], [
                'url' => 'https://appwrite.io/robots.txt',
            ], timeout: 5);

            $this->assertEquals(404, $response['headers']['status-code']);
            $this->assertEquals(Exception::AVATAR_IMAGE_NOT_FOUND, $response['body']['type']);
        }, 30_000, 2_000);

        $response = $this->client->call(Client::METHOD_GET, '/avatars/image', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://appwrite.io/images/open-graph/website.avif',
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
        $this->assertSame(400, $image->getImageWidth());
        $this->assertSame(400, $image->getImageHeight());
        $this->assertSame('PNG', $image->getImageFormat());
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
        $this->assertSame(200, $image->getImageWidth());
        $this->assertSame(200, $image->getImageHeight());
        $this->assertSame('PNG', $image->getImageFormat());
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
        $this->assertSame(200, $image->getImageWidth());
        $this->assertSame(200, $image->getImageHeight());
        $this->assertSame('PNG', $image->getImageFormat());
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
        $this->assertSame(200, $image->getImageWidth());
        $this->assertSame(200, $image->getImageHeight());
        $this->assertSame('PNG', $image->getImageFormat());
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

        $this->assertSame($image->getImageWidth(), $original->getImageWidth());
        $this->assertSame($image->getImageHeight(), $original->getImageHeight());
        $this->assertSame('PNG', $image->getImageFormat());
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

        $this->assertSame($image->getImageWidth(), $original->getImageWidth());
        $this->assertSame($image->getImageHeight(), $original->getImageHeight());
        $this->assertSame('PNG', $image->getImageFormat());
    }

    public function testGetScreenshot(): array
    {
        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://example.com?x=' . time() . rand(1000, 9999),
            'width' => 800,
            'height' => 600,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('image/png', $response['headers']['content-type']);
        $this->assertNotEmpty($response['body']);

        $image = new \Imagick();
        $image->readImageBlob($response['body']);
        $this->assertSame(800, $image->getImageWidth());
        $this->assertSame(600, $image->getImageHeight());
        $this->assertSame('PNG', $image->getImageFormat());

        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://example.com?x=' . time() . rand(1000, 9999),
            'width' => 800,
            'height' => 600,
            'userAgent' => str_repeat('a', 512),
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
            'url' => 'https://example.com?x=' . time() . rand(1000, 9999),
            'width' => 800,
            'height' => 600,
            'headers' => 'invalid-headers-string',
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);

        // Test with numeric headers (should fail)
        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://example.com?x=' . time() . rand(1000, 9999),
            'width' => 800,
            'height' => 600,
            'headers' => 123,
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);

        // Test with boolean headers (should fail)
        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://example.com?x=' . time() . rand(1000, 9999),
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
            'url' => 'https://example.com?x=' . time() . rand(1000, 9999),
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
            'url' => 'https://example.com?x=' . time() . rand(1000, 9999),
            'width' => 800,
            'height' => 600,
            'headers' => ['User-Agent' => 'MyApp', 'value2', 'Accept' => 'text/html'], // Mixed array
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);

        // Test with empty array (should pass - empty associative array)
        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://example.com?x=' . time() . rand(1000, 9999),
            'width' => 800,
            'height' => 600,
            'headers' => [], // Empty associative array should pass
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);

        // Test with valid headers object (should pass)
        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://example.com?x=' . time() . rand(1000, 9999),
            'width' => 800,
            'height' => 600,
            'headers' => [
                'User-Agent' => 'MyApp/1.0',
                'Accept' => 'text/html,application/xhtml+xml',
                'Accept-Language' => 'en-US,en;q=0.9'
            ],
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);

        // Test with headers containing special characters (should pass validation)
        // Note: Authorization/Content-Type headers may cause the target site to respond differently,
        // so the browser service may fail (404) even though parameter validation passes.
        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://example.com?x=' . time() . rand(1000, 9999),
            'width' => 800,
            'height' => 600,
            'headers' => [
                'X-Custom-Header' => 'custom-value',
                'Authorization' => 'Bearer token123',
                'Content-Type' => 'application/json'
            ],
        ]);
        $this->assertContains($response['headers']['status-code'], [200, 404]);

        // Test with custom viewport width and height
        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://example.com?x=' . time() . rand(1000, 9999),
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
            'url' => 'https://example.com?x=' . time() . rand(1000, 9999),
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
            'url' => 'https://example.com?x=' . time() . rand(1000, 9999),
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
            'url' => 'https://example.com?x=' . time() . rand(1000, 9999),
            'viewportWidth' => 0, // Too small
            'viewportHeight' => 720,
            'width' => 800,
            'height' => 600,
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://example.com?x=' . time() . rand(1000, 9999),
            'viewportWidth' => 2000, // Too large
            'viewportHeight' => 720,
            'width' => 800,
            'height' => 600,
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://example.com?x=' . time() . rand(1000, 9999),
            'viewportWidth' => 1280,
            'viewportHeight' => 0, // Too small
            'width' => 800,
            'height' => 600,
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://example.com?x=' . time() . rand(1000, 9999),
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
            'url' => 'https://example.com?x=' . time() . rand(1000, 9999),
            'width' => -1, // Invalid width (negative)
            'height' => 600,
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://example.com?x=' . time() . rand(1000, 9999),
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
            'url' => 'https://example.com?x=' . time() . rand(1000, 9999),
            'width' => 800,
            'height' => 600,
            'sleep' => -1, // Negative sleep
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://example.com?x=' . time() . rand(1000, 9999),
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
            'url' => 'https://example.com?x=' . time() . rand(1000, 9999),
            'width' => 800,
            'height' => 600,
            'quality' => -2, // Too small
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://example.com?x=' . time() . rand(1000, 9999),
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
            'url' => 'https://example.com?x=' . time() . rand(1000, 9999),
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
            'url' => 'https://example.com?x=' . time() . rand(1000, 9999),
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
            'url' => 'https://example.com?x=' . time() . rand(1000, 9999),
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
            'url' => 'https://example.com?x=' . time() . rand(1000, 9999),
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
            'url' => 'https://example.com?x=' . time() . rand(1000, 9999),
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
            'url' => 'https://example.com?x=' . time() . rand(1000, 9999),
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
            'url' => 'https://example.com?x=' . time() . rand(1000, 9999),
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
            'url' => 'https://example.com?x=' . time() . rand(1000, 9999),
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
            'url' => 'https://example.com?x=' . time() . rand(1000, 9999),
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
            'url' => 'https://example.com?x=' . time() . rand(1000, 9999),
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
            'url' => 'https://example.com?x=' . time() . rand(1000, 9999),
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
            'url' => 'https://example.com?x=' . time() . rand(1000, 9999),
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
            'url' => 'https://example.com?x=' . time() . rand(1000, 9999),
            'width' => 800,
            'height' => 600,
            'userAgent' => str_repeat('A', 513), // Too long (max 512)
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);

        // Test invalid fullpage parameter (non-boolean)
        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://example.com?x=' . time() . rand(1000, 9999),
            'width' => 800,
            'height' => 600,
            'fullpage' => 'invalid-boolean',
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);

        // Test invalid locale parameter (too long)
        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://example.com?x=' . time() . rand(1000, 9999),
            'width' => 800,
            'height' => 600,
            'locale' => 'en-US-very-long-locale-string',
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);

        // Test invalid timezone parameter
        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://example.com?x=' . time() . rand(1000, 9999),
            'width' => 800,
            'height' => 600,
            'timezone' => 'Invalid/Timezone',
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);

        // Test invalid latitude parameter (too high)
        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://example.com?x=' . time() . rand(1000, 9999),
            'width' => 800,
            'height' => 600,
            'latitude' => 91, // Too high (max 90)
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);

        // Test invalid latitude parameter (too low)
        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://example.com?x=' . time() . rand(1000, 9999),
            'width' => 800,
            'height' => 600,
            'latitude' => -91, // Too low (min -90)
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);

        // Test invalid longitude parameter (too high)
        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://example.com?x=' . time() . rand(1000, 9999),
            'width' => 800,
            'height' => 600,
            'longitude' => 181, // Too high (max 180)
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);

        // Test invalid longitude parameter (too low)
        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://example.com?x=' . time() . rand(1000, 9999),
            'width' => 800,
            'height' => 600,
            'longitude' => -181, // Too low (min -180)
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);

        // Test invalid accuracy parameter (too high)
        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://example.com?x=' . time() . rand(1000, 9999),
            'width' => 800,
            'height' => 600,
            'accuracy' => 100001, // Too high (max 100000)
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);

        // Test invalid accuracy parameter (negative)
        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://example.com?x=' . time() . rand(1000, 9999),
            'width' => 800,
            'height' => 600,
            'accuracy' => -1, // Negative (min 0)
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);

        // Test invalid touch parameter (non-boolean)
        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://example.com?x=' . time() . rand(1000, 9999),
            'width' => 800,
            'height' => 600,
            'touch' => 'invalid-boolean',
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);

        // Test invalid permissions parameter (non-array)
        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://example.com?x=' . time() . rand(1000, 9999),
            'width' => 800,
            'height' => 600,
            'permissions' => 'invalid-permissions-string',
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);

        // Test valid permissions parameter (should pass validation)
        // Note: Browser service may not support granting permissions in CI,
        // so 404 (AVATAR_REMOTE_URL_FAILED) is acceptable alongside 200.
        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://example.com?x=' . time() . rand(1000, 9999),
            'width' => 800,
            'height' => 600,
            'permissions' => ['geolocation', 'camera', 'microphone'], // This should pass as it's a valid array
        ]);
        $this->assertContains($response['headers']['status-code'], [200, 404]);

        // Test empty permissions array (should pass)
        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://example.com?x=' . time() . rand(1000, 9999),
            'width' => 800,
            'height' => 600,
            'permissions' => [], // Empty array should pass
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);

        // Test invalid permission names (should fail)
        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://example.com?x=' . time() . rand(1000, 9999),
            'width' => 800,
            'height' => 600,
            'permissions' => ['invalid-permission', 'another-invalid'],
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);

        // Test mixed valid and invalid permissions (should fail)
        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://example.com?x=' . time() . rand(1000, 9999),
            'width' => 800,
            'height' => 600,
            'permissions' => ['geolocation', 'invalid-permission'],
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);

        // Test valid permission names (should pass)
        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://example.com?x=' . time() . rand(1000, 9999),
            'width' => 800,
            'height' => 600,
            'permissions' => ['geolocation', 'camera', 'microphone', 'notifications'],
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);

        // Test advanced permission names (should pass)
        $response = $this->client->call(Client::METHOD_GET, '/avatars/screenshots', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => 'https://example.com?x=' . time() . rand(1000, 9999),
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



    public function testGetPhoto(): array
    {
        /**
         * Test for SUCCESS — authenticated user (client side)
         *
         * The endpoint always returns an image: even when no Gravatar/Libravatar
         * avatar exists it falls back to initials or the static placeholder, so
         * we always expect HTTP 200.
         */

        // Default call — uses session user; falls through priority chain and
        // returns some image (initials or static fallback at minimum).
        $response = $this->client->call(Client::METHOD_GET, '/avatars/photo', \array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('image/png', $response['headers']['content-type']);
        $this->assertNotEmpty($response['body']);

        // Width + height
        $response = $this->client->call(Client::METHOD_GET, '/avatars/photo', \array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'width'  => 128,
            'height' => 128,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('image/png', $response['headers']['content-type']);
        $this->assertNotEmpty($response['body']);

        // Quality param
        $response = $this->client->call(Client::METHOD_GET, '/avatars/photo', \array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'width'   => 200,
            'height'  => 200,
            'quality' => 50,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);

        // Output format: webp
        $response = $this->client->call(Client::METHOD_GET, '/avatars/photo', \array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'output' => 'webp',
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('image/webp', $response['headers']['content-type']);
        $this->assertNotEmpty($response['body']);

        // Output format: jpg
        $response = $this->client->call(Client::METHOD_GET, '/avatars/photo', \array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'output' => 'jpg',
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('image/jpeg', $response['headers']['content-type']);
        $this->assertNotEmpty($response['body']);

        // Rating param
        $response = $this->client->call(Client::METHOD_GET, '/avatars/photo', \array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rating' => 'pg',
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);

        /**
         * Test for SUCCESS — Gravatar flow (Priority 2)
         *
         * Priority 1, the OAuth2 identity photo, needs an OAuth2 session and is
         * covered in AvatarsCustomClientTest::testGetPhotoOAuth2.
         *
         * When no OAuth2 identity photo is available the chain falls through to
         * Gravatar. Wrapped in assertEventually to tolerate transient network
         * hiccups.
         */
        $this->assertEventually(function () {
            $response = $this->client->call(Client::METHOD_GET, '/avatars/photo', \array_merge([
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()), [
                'width'  => 256,
                'height' => 256,
            ]);

            $this->assertEquals(200, $response['headers']['status-code']);
            $this->assertEquals('image/png', $response['headers']['content-type']);
            $this->assertNotEmpty($response['body']);
            $this->assertEquals('private, no-store', $response['headers']['cache-control']);
        }, 30_000, 2_000);

        /**
         * Test for FAILURE — invalid params
         */

        // Width out of range
        $response = $this->client->call(Client::METHOD_GET, '/avatars/photo', \array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'width' => 2001,
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);

        // Height out of range
        $response = $this->client->call(Client::METHOD_GET, '/avatars/photo', \array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'height' => 2001,
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);

        // Quality out of range
        $response = $this->client->call(Client::METHOD_GET, '/avatars/photo', \array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'quality' => 101,
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);

        // Invalid output format
        $response = $this->client->call(Client::METHOD_GET, '/avatars/photo', \array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'output' => 'bmp',
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);

        // Invalid rating
        $response = $this->client->call(Client::METHOD_GET, '/avatars/photo', \array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rating' => 'xx',
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);

        return [];
    }

    public function testGetPhotoByEmailHash(): void
    {
        /**
         * Test for SUCCESS
         *
         * The hashed email is registered nowhere, so Gravatar and Libravatar
         * answer 404 and the chain falls through to the static fallback —
         * never to initials, which require a name and must not derive from an
         * email.
         */
        $hash = \hash('sha256', \uniqid('photo-') . '@appwrite.io');

        $response = $this->client->call(Client::METHOD_GET, '/avatars/photo', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'emailHash' => $hash,
            'width' => 100,
            'height' => 100,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('image/png', $response['headers']['content-type']);
        $this->assertPhotoFallback($response['body']);

        // Uppercase hex is normalised rather than rejected.
        $response = $this->client->call(Client::METHOD_GET, '/avatars/photo', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'emailHash' => \strtoupper($hash),
            'width' => 100,
            'height' => 100,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertPhotoFallback($response['body']);

        // A name alongside the hash resolves to initials once Gravatar and
        // Libravatar miss — the explicit parameters replace the authenticated
        // user's own photo sources.
        $response = $this->client->call(Client::METHOD_GET, '/avatars/photo', \array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'emailHash' => $hash,
            'name' => 'W W',
            'width' => 100,
            'height' => 100,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('image/png', $response['headers']['content-type']);
        $this->assertPhotoInitials($response['body']);

        /**
         * Test for FAILURE
         */

        // A raw email address must never be accepted — it would leak into
        // access logs, proxies and browser history.
        $response = $this->client->call(Client::METHOD_GET, '/avatars/photo', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'emailHash' => 'someone@appwrite.io',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        // Too short to be a SHA256 hash.
        $response = $this->client->call(Client::METHOD_GET, '/avatars/photo', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'emailHash' => \substr($hash, 0, 63),
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        // Right length, but not hex.
        $response = $this->client->call(Client::METHOD_GET, '/avatars/photo', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'emailHash' => \str_repeat('z', 64),
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        // An MD5 hash is not a SHA256 hash.
        $response = $this->client->call(Client::METHOD_GET, '/avatars/photo', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'emailHash' => \md5('photo@appwrite.io'),
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);
    }

    public function testGetPhotoByName(): void
    {
        /**
         * Test for SUCCESS — initials render from the provided name, no
         * session required.
         */
        $response = $this->client->call(Client::METHOD_GET, '/avatars/photo', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'name' => 'W W',
            'width' => 100,
            'height' => 100,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('image/png', $response['headers']['content-type']);
        $this->assertPhotoInitials($response['body']);

        // The explicit name replaces the authenticated user's photo sources —
        // the identity-photo case is covered in AvatarsCustomClientTest.
        $response = $this->client->call(Client::METHOD_GET, '/avatars/photo', \array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'W W',
            'width' => 100,
            'height' => 100,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertPhotoInitials($response['body']);

        // '0' is falsy in PHP — it must still count as a provided name and
        // render as initials, never fall back to the user's photo sources.
        $response = $this->client->call(Client::METHOD_GET, '/avatars/photo', \array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => '0',
            'width' => 100,
            'height' => 100,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertPhotoInitials($response['body']);

        // An empty name is allowed and behaves as if it was not passed.
        $response = $this->client->call(Client::METHOD_GET, '/avatars/photo', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'name' => '',
            'width' => 100,
            'height' => 100,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertPhotoFallback($response['body']);

        /**
         * Test for FAILURE
         */

        // Name longer than 128 chars.
        $response = $this->client->call(Client::METHOD_GET, '/avatars/photo', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'name' => \str_repeat('w', 129),
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);
    }

    /**
     * Assert the avatar is generated initials.
     *
     * The surface alone cannot say so — the static fallback draws on the same
     * grey — so this also asserts the person mark is absent.
     */
    private function assertPhotoInitials(string $blob): void
    {
        $this->assertPhotoBackground(self::PHOTO_SURFACE_COLOR, $blob);

        $this->assertFalse(
            $this->photoHasPersonMark($blob),
            'Expected rendered initials but got the static fallback — the provider chain fell through.'
        );
    }

    /**
     * Assert the avatar is the built-in static fallback. When Imagick is
     * missing entirely the endpoint serves the fallback as raw SVG source
     * instead of a drawn PNG, so both encodings are accepted.
     */
    private function assertPhotoFallback(string $blob): void
    {
        if (\str_contains(\substr($blob, 0, 256), '<svg')) {
            $this->assertStringContainsString('#4F4F4F', $blob);

            return;
        }

        $this->assertPhotoBackground(self::PHOTO_SURFACE_COLOR, $blob);

        $this->assertTrue(
            $this->photoHasPersonMark($blob),
            'Expected the static fallback but the avatar carries no person mark.'
        );
    }

    /**
     * Whether the fallback's person mark is drawn.
     *
     * Samples down the mark's left shoulder, which is figure colour on the
     * fallback and bare surface on initials — letters are centred and never
     * reach that far down or out. Sampling a short run rather than one pixel
     * keeps the check clear of the mark's edges at small sizes.
     */
    private function photoHasPersonMark(string $blob): bool
    {
        $image = new \Imagick();
        $image->readImageBlob($blob);

        $x = (int) \round($image->getImageWidth() * 0.34);
        $to = (int) \round($image->getImageHeight() * 0.73);

        for ($y = (int) \round($image->getImageHeight() * 0.68); $y <= $to; $y++) {
            $color = $image->getImagePixelColor($x, $y)->getColor();

            if ($color['r'] > 200 && $color['g'] > 200 && $color['b'] > 200) {
                return true;
            }
        }

        return false;
    }

    /**
     * Assert both top corners of an avatar match an expected background
     * colour. Corners are always background — initials are drawn in the
     * centre and the person mark never reaches the top edge.
     */
    private function assertPhotoBackground(array $rgb, string $blob): void
    {
        $this->assertNotEmpty($blob);

        // The static fallback is SVG, which ImageMagick may refuse to open at
        // all under its security policy. Name it here rather than letting
        // readImageBlob() raise an opaque ImagickException — reaching the
        // fallback when initials were expected is the failure worth reporting.
        $this->assertStringNotContainsString(
            '<svg',
            \substr($blob, 0, 256),
            'Expected a rendered avatar but got the static SVG fallback — the provider chain fell through.'
        );

        $image = new \Imagick();
        $image->readImageBlob($blob);

        foreach ([[2, 2], [$image->getImageWidth() - 3, 2]] as [$x, $y]) {
            $color = $image->getImagePixelColor($x, $y)->getColor();

            $this->assertSame(
                $rgb,
                ['r' => $color['r'], 'g' => $color['g'], 'b' => $color['b']],
                "Pixel at {$x},{$y} does not match the expected avatar background."
            );
        }
    }
}
