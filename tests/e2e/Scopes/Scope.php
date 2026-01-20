<?php

namespace Tests\E2E\Scopes;

use Appwrite\Tests\Async;
use Appwrite\Tests\Retryable;
use PHPUnit\Framework\TestCase;
use Tests\E2E\Client;
use Utopia\Database\Helpers\ID;
use Utopia\System\System;

abstract class Scope extends TestCase
{
    use Retryable;
    use Async;

    public const REQUEST_TYPE_WEBHOOK = 'webhook';
    public const REQUEST_TYPE_SMS = 'sms';

    protected ?Client $client = null;
    protected string $endpoint = 'http://appwrite.test/v1';

    protected function setUp(): void
    {
        $this->client = new Client();
        $this->client->setEndpoint($this->endpoint);

        $format = System::getEnv('_APP_E2E_RESPONSE_FORMAT');
        if (!empty($format)) {
            if (
                !\preg_match('/^\d+\.\d+\.\d+$/', $format) ||
                !\version_compare($format, APP_VERSION_STABLE, '<=')
            ) {
                throw new \Exception('E2E response format must be ' . APP_VERSION_STABLE . ' or lower.');
            }
            $this->client->setResponseFormat($format);
        }
    }

    protected function tearDown(): void
    {
        $this->client = null;
    }

    protected function getLastEmail(int $limit = 1): array
    {
        sleep(3);

        $emails = json_decode(file_get_contents('http://maildev:1080/email'), true);

        if ($emails && is_array($emails)) {
            if ($limit === 1) {
                return end($emails);
            } else {
                return array_slice($emails, -1 * $limit);
            }
        }

        return [];
    }

    protected function extractQueryParamsFromEmailLink(string $html): array
    {
        foreach (['/join-us?', '/verification?', '/recovery?'] as $prefix) {
            $linkStart = strpos($html, $prefix);
            if ($linkStart !== false) {
                $hrefStart = strrpos(substr($html, 0, $linkStart), 'href="');
                if ($hrefStart === false) {
                    continue;
                }

                $hrefStart += 6;
                $hrefEnd = strpos($html, '"', $hrefStart);
                if ($hrefEnd === false || $hrefStart >= $hrefEnd) {
                    continue;
                }

                $link = substr($html, $hrefStart, $hrefEnd - $hrefStart);
                $link = strtok($link, '#'); // Remove `#title`
                $queryStart = strpos($link, '?');
                if ($queryStart === false) {
                    continue;
                }

                $queryString = substr($link, $queryStart + 1);
                parse_str(html_entity_decode($queryString), $queryParams);
                return $queryParams;
            }
        }

        return [];
    }

    protected function assertLastRequest(callable $probe, string $type, $timeoutMs = 20_000, $waitMs = 500): array
    {
        $hostname = match ($type) {
            'webhook' => 'request-catcher-webhook',
            'sms' => 'request-catcher-sms',
            default => throw new \Exception('Invalid request catcher type.'),
        };

        $this->assertEventually(function () use (&$request, $probe, $hostname) {
            $request = json_decode(file_get_contents('http://' . $hostname . ':5000/__last_request__'), true);
            $request['data'] = json_decode($request['data'], true);

            call_user_func($probe, $request);
        }, $timeoutMs, $waitMs);

        return $request;
    }

    protected function assertSamePixels(string $expectedImagePath, string $actualImageBlob): void
    {
        $expected = new \Imagick($expectedImagePath);
        $actual = new \Imagick();
        $actual->readImageBlob($actualImageBlob);

        foreach ([$expected, $actual] as $image) {
            $image->setImageFormat('PNG');
            $image->stripImage();
            $image->setOption('png:exclude-chunks', 'date,time,iCCP,sRGB,gAMA,cHRM');
        }

        $this->assertSame($expected->getImageSignature(), $actual->getImageSignature());
    }

    /**
     * @deprecated Use assertLastRequest instead. Used only historically in webhook tests
     */
    protected function getLastRequest(): array
    {
        $hostname = 'request-catcher-webhook';

        sleep(2);

        $request = json_decode(file_get_contents('http://' . $hostname . ':5000/__last_request__'), true);
        $request['data'] = json_decode($request['data'], true);

        return $request;
    }

    /**
     * Wait for all attributes in a collection to be available.
     *
     * @param string $databaseId
     * @param string $collectionId
     * @param int $timeoutMs Maximum time to wait in milliseconds
     * @param int $waitMs Time between polling attempts in milliseconds
     */
    protected function waitForAttributes(string $databaseId, string $collectionId, ?string $projectId = null, ?string $apiKey = null, int $timeoutMs = 10000, int $waitMs = 100): void
    {
        $projectId = $projectId ?? $this->getProject()['$id'];
        $headers = $apiKey ? ['x-appwrite-key' => $apiKey] : $this->getHeaders();

        $this->assertEventually(function () use ($databaseId, $collectionId, $projectId, $headers) {
            $collection = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
            ], $headers));

            $this->assertEquals(200, $collection['headers']['status-code']);

            foreach ($collection['body']['attributes'] ?? [] as $attribute) {
                $this->assertEquals('available', $attribute['status'], 'Attribute ' . $attribute['key'] . ' not available');
            }
        }, $timeoutMs, $waitMs);
    }

    /**
     * @return array
     */
    abstract public function getHeaders(bool $devKey = true): array;

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

        $session = $session['cookies']['a_session_console'];

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
    public function getUser(bool $fresh = false): array
    {
        if (!$fresh && isset(self::$user[$this->getProject()['$id']])) {
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

        $token = $session['cookies']['a_session_' . $this->getProject()['$id']];

        self::$user[$this->getProject()['$id']] = [
            '$id' => ID::custom($user['body']['$id']),
            'name' => $user['body']['name'],
            'email' => $user['body']['email'],
            'session' => $token,
            'sessionId' => $session['body']['$id'],
        ];

        return self::$user[$this->getProject()['$id']];
    }
}
