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
    protected string $endpoint = 'http://appwrite/v1';
    protected string $webEndpoint = 'http://appwrite.test/v1';

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

    /**
     * Get the last email sent to a specific address.
     * This is more reliable than getLastEmail() when tests run in parallel.
     */
    protected function getLastEmailByAddress(string $address): array
    {
        sleep(3);

        $emails = json_decode(file_get_contents('http://maildev:1080/email'), true);

        if ($emails && is_array($emails)) {
            // Search from the end (most recent) to the beginning
            for ($i = count($emails) - 1; $i >= 0; $i--) {
                $email = $emails[$i];
                if (isset($email['to']) && is_array($email['to'])) {
                    foreach ($email['to'] as $recipient) {
                        if (isset($recipient['address']) && $recipient['address'] === $address) {
                            return $email;
                        }
                    }
                }
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
     * @deprecated Use getLastRequestForProject instead. Used only historically in webhook tests
     */
    protected function getLastRequest(): array
    {
        $project = $this->getProject();
        $this->assertArrayHasKey('$id', $project, 'Project must have an $id');
        return $this->getLastRequestForProject($project['$id']);
    }

    /**
     * Get the last webhook request for a specific project.
     * Polls with retry to handle parallel test race conditions.
     */
    protected function getLastRequestForProject(
        string $projectId,
        string $type = self::REQUEST_TYPE_WEBHOOK,
        array $queryParams = [],
        int $maxAttempts = 10,
        int $delayMs = 500,
        ?callable $probe = null
    ): array {
        $hostname = match ($type) {
            self::REQUEST_TYPE_WEBHOOK => 'request-catcher-webhook',
            self::REQUEST_TYPE_SMS => 'request-catcher-sms',
            default => throw new \Exception('Invalid request catcher type.'),
        };
        $enforceProjectId = $type === self::REQUEST_TYPE_WEBHOOK;

        if (empty($queryParams)) {
            $queryParams = [
                'header_X-Appwrite-Webhook-Project-Id' => $projectId,
            ];
        }

        $query = http_build_query($queryParams);

        sleep(2);

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $requests = json_decode(file_get_contents('http://' . $hostname . ':5000/__find_request__?' . $query), true);
            if (is_array($requests)) {
                for ($i = count($requests) - 1; $i >= 0; $i--) {
                    $request = $this->decodeRequestData($requests[$i]);
                    if ($probe !== null) {
                        try {
                            $probe($request);
                            return $request;
                        } catch (\Throwable $error) {
                            continue;
                        }
                    }

                    if ($enforceProjectId) {
                        $requestProjectId = $request['headers']['X-Appwrite-Webhook-Project-Id'] ?? '';
                        if ($requestProjectId === $projectId) {
                            return $request;
                        }
                    } else {
                        return $request;
                    }
                }
            }

            usleep($delayMs * 1000);
        }

        $requests = json_decode(file_get_contents('http://' . $hostname . ':5000/__find_request__?' . $query), true);
        if (is_array($requests)) {
            for ($i = count($requests) - 1; $i >= 0; $i--) {
                $request = $this->decodeRequestData($requests[$i]);
                if ($probe !== null) {
                    try {
                        $probe($request);
                        return $request;
                    } catch (\Throwable $error) {
                        continue;
                    }
                }

                if ($enforceProjectId) {
                    $requestProjectId = $request['headers']['X-Appwrite-Webhook-Project-Id'] ?? '';
                    if ($requestProjectId === $projectId) {
                        return $request;
                    }
                } else {
                    return $request;
                }
            }
        }

        return [];
    }

    protected function decodeRequestData(array $request): array
    {
        if (!array_key_exists('data', $request)) {
            return $request;
        }

        if (is_array($request['data'])) {
            return $request;
        }

        if (!is_string($request['data']) || $request['data'] === '') {
            return $request;
        }

        $decoded = json_decode($request['data'], true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $request['data'] = $decoded;
            return $request;
        }

        parse_str($request['data'], $parsed);
        if (!empty($parsed)) {
            $request['data'] = $parsed;
        }

        return $request;
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
