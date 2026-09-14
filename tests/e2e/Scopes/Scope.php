<?php

declare(strict_types=1);

namespace Tests\E2E\Scopes;

use Appwrite\Database\Factory;
use Appwrite\Tests\Async;
use Appwrite\Tests\Retryable;
use PHPUnit\Framework\TestCase;
use Tests\E2E\Client;
use Utopia\Cache\Adapter\Pool as CachePool;
use Utopia\Cache\Adapter\Sharding;
use Utopia\Cache\Cache;
use Utopia\Config\Config;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Validator\Authorization;
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

    /**
     * Seed a pre-existing console organization for tests of projects, permissions,
     * memberships, and legacy multi-org access. This is database fixture setup,
     * not a successful POST /teams: the returned response is a real GET (200).
     * Application teams still use the public create API (201).
     */
    protected function createTeamFixture(array $headers, array $params): array
    {
        if (($headers['x-appwrite-project'] ?? '') !== 'console') {
            return $this->client->call(Client::METHOD_POST, '/teams', $headers, $params);
        }

        // Read console fixtures as their owner, not in project admin mode.
        unset($headers['x-appwrite-mode']);

        // The first organization of a self-hosted instance, and every organization on an
        // edition without the instance limit, comes from the public API. Only a refusal
        // falls through to seeding the rows directly.
        $team = $this->client->call(Client::METHOD_POST, '/teams', $headers, $params);
        if ($team['headers']['status-code'] === 201) {
            $response = $this->client->call(Client::METHOD_GET, '/teams/' . $team['body']['$id'], $headers);
            $this->assertSame(200, $response['headers']['status-code']);
            return $response;
        }
        $this->assertSame(403, $team['headers']['status-code']);
        $this->assertSame('organization_creation_prohibited', $team['body']['type']);

        $account = [];
        $this->assertEventually(function () use ($headers, &$account) {
            $account = $this->client->call(Client::METHOD_GET, '/account', $headers);
            $this->assertSame(200, $account['headers']['status-code']);
        }, 3_000, 100);

        $teamId = ($params['teamId'] ?? 'unique()') === 'unique()' ? ID::unique() : $params['teamId'];
        $seed = function () use ($account, $params, $teamId) {
            global $register;
            $pools = $register->get('pools');
            $cache = new Cache(new Sharding(array_map(
                fn (string $name) => new CachePool($pools->get($name)),
                Config::getParam('pools-cache', []),
            )));
            $authorization = new Authorization();
            $database = (new Factory($pools, $cache, $authorization))->platform();

            $authorization->skip(function () use ($database, $account, $params, $teamId) {
                $database->withTransaction(function () use ($database, $account, $params, $teamId) {
                    $user = $database->getDocument('users', $account['body']['$id']);
                    $team = $database->createDocument('teams', new Document([
                        '$id' => $teamId,
                        '$permissions' => [
                            Permission::read(Role::team($teamId)),
                            Permission::update(Role::team($teamId, 'owner')),
                            Permission::delete(Role::team($teamId, 'owner')),
                        ],
                        'name' => $params['name'],
                        'total' => 1,
                        'prefs' => new \stdClass(),
                        'search' => $teamId . ' ' . $params['name'],
                    ]));
                    $roles = array_values(array_unique([...($params['roles'] ?? []), 'owner']));
                    $membershipId = ID::unique();
                    $database->createDocument('memberships', new Document([
                        '$id' => $membershipId,
                        '$permissions' => [
                            Permission::read(Role::user($user->getId())),
                            Permission::read(Role::team($teamId)),
                            Permission::update(Role::user($user->getId())),
                            Permission::update(Role::team($teamId, 'owner')),
                            Permission::delete(Role::user($user->getId())),
                            Permission::delete(Role::team($teamId, 'owner')),
                        ],
                        'userId' => $user->getId(),
                        'userInternalId' => $user->getSequence(),
                        'teamId' => $teamId,
                        'teamInternalId' => $team->getSequence(),
                        'roles' => $roles,
                        'invited' => DateTime::now(),
                        'joined' => DateTime::now(),
                        'confirm' => true,
                        'secret' => '',
                        'search' => $membershipId . ' ' . $user->getId(),
                    ]));
                });
                $database->purgeCachedDocument('users', $account['body']['$id']);
            });
        };
        if (\Swoole\Coroutine::getCid() >= 0) {
            $seed();
        } else {
            \Swoole\Coroutine\run($seed);
        }

        $response = $this->client->call(Client::METHOD_GET, '/teams/' . $teamId, $headers);
        $this->assertSame(200, $response['headers']['status-code']);
        return $response;
    }

    /**
     * @var array|null Cached console variables
     */
    protected static ?array $consoleVariables = null;

    /**
     * Fetch console variables from the API
     */
    protected function getConsoleVariables(): array
    {
        if (self::$consoleVariables !== null) {
            return self::$consoleVariables;
        }

        $root = $this->getRoot();

        for ($i = 0; $i < 3; $i++) {
            $response = $this->client->call(Client::METHOD_GET, '/console/variables', [
                'origin' => 'http://localhost',
                'content-type' => 'application/json',
                'x-appwrite-project' => 'console',
                'cookie' => 'a_session_console=' . $root['session'],
            ]);

            if ($response['headers']['status-code'] === 200 && !empty($response['body'])) {
                self::$consoleVariables = $response['body'];
                return self::$consoleVariables;
            }

            \usleep(500000);
        }

        self::$consoleVariables = $response['body'] ?? [];

        return self::$consoleVariables;
    }

    /**
     * Check if the database adapter supports relationships
     */
    protected function getSupportForRelationships(): bool
    {
        return $this->getConsoleVariables()['supportForRelationships'] ?? true;
    }

    /**
     * Check if the database adapter supports operators
     */
    protected function getSupportForOperators(): bool
    {
        return $this->getConsoleVariables()['supportForOperators'] ?? true;
    }

    /**
     * Check if the database adapter supports spatial attributes
     */
    protected function getSupportForSpatials(): bool
    {
        return $this->getConsoleVariables()['supportForSpatials'] ?? true;
    }

    /**
     * Check if the database adapter supports spatial indexes on nullable columns
     */
    protected function getSupportForSpatialIndexNull(): bool
    {
        return $this->getConsoleVariables()['supportForSpatialIndexNull'] ?? false;
    }

    /**
     * Check if the database adapter supports fulltext wildcard search
     */
    protected function getSupportForFulltextWildcard(): bool
    {
        return $this->getConsoleVariables()['supportForFulltextWildcard'] ?? true;
    }

    /**
     * Check if the database adapter supports multiple fulltext indexes per collection
     */
    protected function getSupportForMultipleFulltextIndexes(): bool
    {
        return $this->getConsoleVariables()['supportForMultipleFulltextIndexes'] ?? true;
    }

    /**
     * Check if the database adapter supports attributes
     */
    protected function getSupportForAttributes(): bool
    {
        return $this->getConsoleVariables()['supportForAttributes'] ?? true;
    }

    /**
     * Get the maximum index length supported by the database adapter
     */
    protected function getMaxIndexLength(): int
    {
        return $this->getConsoleVariables()['maxIndexLength'] ?? 767;
    }

    protected function getLastEmail(int $limit = 1, ?callable $probe = null): array
    {
        $result = [];
        $this->assertEventually(function () use (&$result, $limit, $probe) {
            $emails = json_decode(file_get_contents('http://maildev:1080/email'), true);

            $this->assertNotEmpty($emails, 'Maildev should have at least one email');
            $this->assertIsArray($emails);

            if ($probe !== null && $limit === 1) {
                for ($i = count($emails) - 1; $i >= 0; $i--) {
                    try {
                        $probe($emails[$i]);
                        $result = $emails[$i];
                        return;
                    } catch (\Throwable) {
                        continue;
                    }
                }
                $this->fail('No email matching probe found');
            } elseif ($limit === 1) {
                $result = end($emails);
            } else {
                $result = array_slice($emails, -1 * $limit);
                $this->assertCount($limit, $result, "Expected {$limit} emails but only got " . count($result));
            }

            $this->assertNotEmpty($result, 'Expected email result to be non-empty');
        }, 15_000, 500);

        return $result;
    }

    /**
     * Get the last email sent to a specific address.
     * This is more reliable than getLastEmail() when tests run in parallel.
     */
    protected function getLastEmailByAddress(string $address, ?callable $probe = null): array
    {
        $result = [];
        $this->assertEventually(function () use (&$result, $address, $probe) {
            $emails = json_decode(file_get_contents('http://maildev:1080/email'), true);

            $this->assertNotEmpty($emails, 'Maildev should have at least one email');
            $this->assertIsArray($emails);

            // Search from the end (most recent) to the beginning
            for ($i = count($emails) - 1; $i >= 0; $i--) {
                $email = $emails[$i];
                if (isset($email['to']) && is_array($email['to'])) {
                    foreach ($email['to'] as $recipient) {
                        if (isset($recipient['address']) && $recipient['address'] === $address) {
                            if ($probe !== null) {
                                try {
                                    $probe($email);
                                } catch (\Throwable) {
                                    continue 2;
                                }
                            }
                            $result = $email;
                            return;
                        }
                    }
                }
            }

            $this->fail("No email found for address: {$address}" . ($probe !== null ? ' matching probe' : ''));
        }, 15_000, 500);

        return $result;
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
    protected function getLastRequest(?callable $probe = null): array
    {
        $project = $this->getProject();
        $this->assertArrayHasKey('$id', $project, 'Project must have an $id');
        return $this->getLastRequestForProject($project['$id'], self::REQUEST_TYPE_WEBHOOK, [], 10, 500, $probe);
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
        $url = 'http://' . $hostname . ':5000/__find_request__?' . $query;

        for ($attempt = 0; $attempt <= $maxAttempts; $attempt++) {
            $response = @file_get_contents($url);
            $requests = is_string($response) ? json_decode($response, true) : [];

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

            if ($attempt < $maxAttempts) {
                usleep($delayMs * 1000);
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

        $maxRetries = 5;

        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            // Use more entropy to avoid collisions in parallel test execution
            $email = uniqid('', true) . getmypid() . bin2hex(random_bytes(4)) . '@localhost.test';
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

            if ($root['headers']['status-code'] !== 201) {
                \usleep(500000);
                continue;
            }

            $session = $this->client->call(Client::METHOD_POST, '/account/sessions/email', [
                'origin' => 'http://localhost',
                'content-type' => 'application/json',
                'x-appwrite-project' => 'console',
            ], [
                'email' => $email,
                'password' => $password,
            ]);

            if (empty($session['cookies']['a_session_console'])) {
                \usleep(500000);
                continue;
            }

            // Verify session is valid before returning
            $verify = $this->client->call(Client::METHOD_GET, '/account', [
                'origin' => 'http://localhost',
                'content-type' => 'application/json',
                'cookie' => 'a_session_console=' . $session['cookies']['a_session_console'],
                'x-appwrite-project' => 'console',
            ]);

            if ($verify['headers']['status-code'] === 200) {
                self::$root = [
                    '$id' => ID::custom($root['body']['$id']),
                    'name' => $root['body']['name'],
                    'email' => $root['body']['email'],
                    'session' => $session['cookies']['a_session_console'],
                ];

                return self::$root;
            }

            \usleep(500000);
        }

        $this->fail('Failed to create and verify root session after ' . $maxRetries . ' attempts');
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
        $projectId = $this->getProject()['$id'];

        if (!$fresh && isset(self::$user[$projectId])) {
            return self::$user[$projectId];
        }

        // Use more entropy to avoid collisions in parallel test execution
        $email = uniqid('', true) . getmypid() . bin2hex(random_bytes(4)) . '@localhost.test';
        $password = 'password';
        $name = 'User Name';

        $user = $this->client->call(Client::METHOD_POST, '/account', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
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
            'x-appwrite-project' => $projectId,
        ], [
            'email' => $email,
            'password' => $password,
        ]);

        self::$user[$projectId] = [
            '$id' => ID::custom($user['body']['$id']),
            'name' => $user['body']['name'],
            'email' => $user['body']['email'],
            'session' => $session['cookies']['a_session_' . $projectId],
            'sessionId' => $session['body']['$id'],
        ];

        return self::$user[$projectId];
    }
}
