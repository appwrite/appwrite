<?php

namespace Tests\E2E\Services\Projects;

use Tests\E2E\Client;
use Utopia\Database\Helpers\ID;
use Utopia\System\System;

trait ProjectsBase
{
    private static array $cachedProjectData = [];
    private static array $cachedProjectWithWebhook = [];
    private static array $cachedProjectWithKey = [];
    private static array $cachedProjectWithPlatform = [];
    private static array $cachedProjectWithVariable = [];
    private static array $cachedProjectWithAuthLimit = [];
    private static array $cachedProjectWithServicesDisabled = [];

    /**
     * Setup and cache a basic project with team
     */
    protected function setupProjectData(): array
    {
        if (!empty(self::$cachedProjectData)) {
            return self::$cachedProjectData;
        }

        $team = $this->client->call(Client::METHOD_POST, '/teams', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'teamId' => ID::unique(),
            'name' => 'Project Test',
        ]);

        $this->assertEquals(201, $team['headers']['status-code']);

        $project = $this->client->call(Client::METHOD_POST, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'projectId' => ID::unique(),
            'name' => 'Project Test',
            'teamId' => $team['body']['$id'],
            'region' => System::getEnv('_APP_REGION', 'default')
        ]);

        $this->assertEquals(201, $project['headers']['status-code']);

        self::$cachedProjectData = [
            'projectId' => $project['body']['$id'],
            'teamId' => $team['body']['$id']
        ];

        return self::$cachedProjectData;
    }

    /**
     * Setup and cache a project with a webhook
     */
    protected function setupProjectWithWebhook(): array
    {
        if (!empty(self::$cachedProjectWithWebhook)) {
            return self::$cachedProjectWithWebhook;
        }

        $projectData = $this->setupProjectData();
        $id = $projectData['projectId'];

        $response = $this->client->call(Client::METHOD_POST, '/projects/' . $id . '/webhooks', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Webhook Test',
            'events' => ['users.*.create', 'users.*.update.email'],
            'url' => 'https://appwrite.io',
            'security' => true,
            'httpUser' => 'username',
            'httpPass' => 'password',
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        self::$cachedProjectWithWebhook = array_merge($projectData, [
            'webhookId' => $response['body']['$id'],
            'signatureKey' => $response['body']['signatureKey']
        ]);

        return self::$cachedProjectWithWebhook;
    }

    /**
     * Setup and cache a project with an API key
     */
    protected function setupProjectWithKey(): array
    {
        if (!empty(self::$cachedProjectWithKey)) {
            return self::$cachedProjectWithKey;
        }

        $projectData = $this->setupProjectData();
        $id = $projectData['projectId'];

        $response = $this->client->call(Client::METHOD_POST, '/projects/' . $id . '/keys', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Key Test',
            'scopes' => ['teams.read', 'teams.write'],
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        self::$cachedProjectWithKey = array_merge($projectData, [
            'keyId' => $response['body']['$id'],
            'secret' => $response['body']['secret']
        ]);

        return self::$cachedProjectWithKey;
    }

    /**
     * Setup and cache a project with platforms
     */
    protected function setupProjectWithPlatform(): array
    {
        if (!empty(self::$cachedProjectWithPlatform)) {
            return self::$cachedProjectWithPlatform;
        }

        $projectData = $this->setupProjectData();
        $id = $projectData['projectId'];

        // Create web platform
        $response = $this->client->call(Client::METHOD_POST, '/projects/' . $id . '/platforms', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'type' => 'web',
            'name' => 'Web App',
            'hostname' => 'localhost',
        ]);
        $this->assertEquals(201, $response['headers']['status-code']);
        $platformWebId = $response['body']['$id'];

        // Create flutter-ios platform
        $response = $this->client->call(Client::METHOD_POST, '/projects/' . $id . '/platforms', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'type' => 'flutter-ios',
            'name' => 'Flutter App (iOS)',
            'key' => 'com.example.ios',
        ]);
        $this->assertEquals(201, $response['headers']['status-code']);
        $platformFultteriOSId = $response['body']['$id'];

        // Create flutter-android platform
        $response = $this->client->call(Client::METHOD_POST, '/projects/' . $id . '/platforms', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'type' => 'flutter-android',
            'name' => 'Flutter App (Android)',
            'key' => 'com.example.android',
        ]);
        $this->assertEquals(201, $response['headers']['status-code']);
        $platformFultterAndroidId = $response['body']['$id'];

        // Create flutter-web platform
        $response = $this->client->call(Client::METHOD_POST, '/projects/' . $id . '/platforms', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'type' => 'flutter-web',
            'name' => 'Flutter App (Web)',
            'hostname' => 'flutter.appwrite.io',
        ]);
        $this->assertEquals(201, $response['headers']['status-code']);
        $platformFultterWebId = $response['body']['$id'];

        // Create apple-ios platform
        $response = $this->client->call(Client::METHOD_POST, '/projects/' . $id . '/platforms', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'type' => 'apple-ios',
            'name' => 'iOS App',
            'key' => 'com.example.ios',
        ]);
        $this->assertEquals(201, $response['headers']['status-code']);
        $platformAppleIosId = $response['body']['$id'];

        // Create apple-macos platform
        $response = $this->client->call(Client::METHOD_POST, '/projects/' . $id . '/platforms', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'type' => 'apple-macos',
            'name' => 'macOS App',
            'key' => 'com.example.macos',
        ]);
        $this->assertEquals(201, $response['headers']['status-code']);
        $platformAppleMacOsId = $response['body']['$id'];

        // Create apple-watchos platform
        $response = $this->client->call(Client::METHOD_POST, '/projects/' . $id . '/platforms', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'type' => 'apple-watchos',
            'name' => 'watchOS App',
            'key' => 'com.example.watchos',
        ]);
        $this->assertEquals(201, $response['headers']['status-code']);
        $platformAppleWatchOsId = $response['body']['$id'];

        // Create apple-tvos platform
        $response = $this->client->call(Client::METHOD_POST, '/projects/' . $id . '/platforms', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'type' => 'apple-tvos',
            'name' => 'tvOS App',
            'key' => 'com.example.tvos',
        ]);
        $this->assertEquals(201, $response['headers']['status-code']);
        $platformAppleTvOsId = $response['body']['$id'];

        self::$cachedProjectWithPlatform = array_merge($projectData, [
            'platformWebId' => $platformWebId,
            'platformFultteriOSId' => $platformFultteriOSId,
            'platformFultterAndroidId' => $platformFultterAndroidId,
            'platformFultterWebId' => $platformFultterWebId,
            'platformAppleIosId' => $platformAppleIosId,
            'platformAppleMacOsId' => $platformAppleMacOsId,
            'platformAppleWatchOsId' => $platformAppleWatchOsId,
            'platformAppleTvOsId' => $platformAppleTvOsId,
        ]);

        return self::$cachedProjectWithPlatform;
    }

    /**
     * Setup and cache a project with variables
     */
    protected function setupProjectWithVariable(): array
    {
        if (!empty(self::$cachedProjectWithVariable)) {
            return self::$cachedProjectWithVariable;
        }

        $projectData = $this->setupProjectData();

        // Create a non-secret variable
        $variable = $this->client->call(Client::METHOD_POST, '/project/variables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectData['projectId'],
            'x-appwrite-mode' => 'admin',
        ], $this->getHeaders()), [
            'key' => 'APP_TEST',
            'value' => 'TESTINGVALUE',
            'secret' => false
        ]);

        $this->assertEquals(201, $variable['headers']['status-code']);
        $variableId = $variable['body']['$id'];

        // Create a secret variable
        $variable = $this->client->call(Client::METHOD_POST, '/project/variables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectData['projectId'],
            'x-appwrite-mode' => 'admin',
        ], $this->getHeaders()), [
            'key' => 'APP_TEST_1',
            'value' => 'TESTINGVALUE_1',
            'secret' => true
        ]);

        $this->assertEquals(201, $variable['headers']['status-code']);
        $secretVariableId = $variable['body']['$id'];

        self::$cachedProjectWithVariable = array_merge($projectData, [
            'variableId' => $variableId,
            'secretVariableId' => $secretVariableId
        ]);

        return self::$cachedProjectWithVariable;
    }

    /**
     * Setup and cache a project with auth limit configured
     */
    protected function setupProjectWithAuthLimit(): array
    {
        if (!empty(self::$cachedProjectWithAuthLimit)) {
            return self::$cachedProjectWithAuthLimit;
        }

        $projectData = $this->setupProjectData();
        $id = $projectData['projectId'];

        // Set auth limit to 0 (unlimited) for the base setup
        $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $id . '/auth/limit', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'limit' => 0,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        self::$cachedProjectWithAuthLimit = $projectData;

        return self::$cachedProjectWithAuthLimit;
    }

    /**
     * Setup and cache a project with services disabled
     */
    protected function setupProjectWithServicesDisabled(): array
    {
        if (!empty(self::$cachedProjectWithServicesDisabled)) {
            return self::$cachedProjectWithServicesDisabled;
        }

        $team = $this->client->call(Client::METHOD_POST, '/teams', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
        ]), [
            'teamId' => ID::unique(),
            'name' => 'Project Test',
        ]);
        $this->assertEquals(201, $team['headers']['status-code']);

        $project = $this->client->call(Client::METHOD_POST, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
        ]), [
            'projectId' => ID::unique(),
            'name' => 'Project Test',
            'teamId' => $team['body']['$id'],
            'region' => System::getEnv('_APP_REGION', 'default')
        ]);

        $this->assertEquals(201, $project['headers']['status-code']);

        $id = $project['body']['$id'];
        $services = require(__DIR__ . '/../../../../app/config/services.php');

        // Disable all optional services
        foreach ($services as $service) {
            if (!$service['optional']) {
                continue;
            }

            $key = $service['key'] ?? '';

            $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $id . '/service', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            ]), [
                'service' => $key,
                'status' => false,
            ]);

            $this->assertEquals(200, $response['headers']['status-code']);
        }

        // Re-enable all services for the cached project
        foreach ($services as $service) {
            if (!$service['optional']) {
                continue;
            }

            $key = $service['key'] ?? '';

            $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $id . '/service/', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()), [
                'service' => $key,
                'status' => true,
            ]);
        }

        self::$cachedProjectWithServicesDisabled = ['projectId' => $id];

        return self::$cachedProjectWithServicesDisabled;
    }

    protected function setupProject(mixed $params, ?string $teamId = null, bool $newTeam = true): string
    {
        if ($newTeam) {
            $team = $this->client->call(Client::METHOD_POST, '/teams', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()), [
                'teamId' => $teamId ?? ID::unique(),
                'name' => 'Project Test',
            ]);

            $this->assertEquals(201, $team['headers']['status-code'], 'Setup team failed with status code: ' . $team['headers']['status-code'] . ' and response: ' . json_encode($team['body'], JSON_PRETTY_PRINT));

            $teamId = $team['body']['$id'];
        }

        $project = $this->client->call(Client::METHOD_POST, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            ...$params,
            'teamId' => $teamId,
        ]);

        $this->assertEquals(201, $project['headers']['status-code'], 'Setup project failed with status code: ' . $project['headers']['status-code'] . ' and response: ' . json_encode($project['body'], JSON_PRETTY_PRINT));

        return $project['body']['$id'];
    }

    protected function setupDevKey(mixed $params): array
    {
        $devKey = $this->client->call(Client::METHOD_POST, '/projects/' . $params['projectId'] . '/dev-keys', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), $params);

        $this->assertEquals(201, $devKey['headers']['status-code'], 'Setup devKey failed with status code: ' . $devKey['headers']['status-code'] . ' and response: ' . json_encode($devKey['body'], JSON_PRETTY_PRINT));

        return [
            '$id' => $devKey['body']['$id'],
            'secret' => $devKey['body']['secret'],
        ];
    }

    protected function setupUserMembership(mixed $params): array
    {
        // Create membership
        $response = $this->client->call(Client::METHOD_POST, '/teams/' . $params['teamId'] . '/memberships', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'email' => $params['email'],
            'name' => $params['name'],
            'roles' => $params['roles'],
            'url' => 'http://localhost:5000/join-us#title'
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertNotEmpty($response['body']['userId']);
        $this->assertEquals($params['name'], $response['body']['userName']);
        $this->assertEquals($params['email'], $response['body']['userEmail']);
        $this->assertNotEmpty($response['body']['teamId']);
        $this->assertCount(count($params['roles']), $response['body']['roles']);
        $this->assertEquals(false, $response['body']['confirm']);

        $userId = $response['body']['userId'];
        $membershipId = $response['body']['$id'];


        $lastEmail = $this->getLastEmail();
        $tokens = $this->extractQueryParamsFromEmailLink($lastEmail['html']);
        $userId = $tokens['userId'];
        $secret = $tokens['secret'];
        // Confirm membership
        $response = $this->client->call(Client::METHOD_PATCH, '/teams/' . $params['teamId'] . '/memberships/' . $membershipId . '/status', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'userId' => $userId,
            'secret' => $secret,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertNotEmpty($response['body']['userId']);
        $this->assertNotEmpty($response['body']['teamId']);
        $this->assertCount(count($params['roles']), $response['body']['roles']);
        $this->assertEquals(true, $response['body']['confirm']);

        // Simulate password recovery flow to reset password for the created user (useful when creating session for this user)
        $response = $this->client->call(Client::METHOD_POST, '/account/recovery', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $params['email'],
            'url' => 'http://localhost/recovery',
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEmpty($response['body']['secret']);

        $lastEmail = $this->getLastEmail();
        $this->assertEquals($params['email'], $lastEmail['to'][0]['address']);
        $this->assertEquals($params['name'], $lastEmail['to'][0]['name']);
        $this->assertEquals('Password Reset for ' . $this->getProject()['name'], $lastEmail['subject']);
        $this->assertStringContainsStringIgnoringCase('Reset your ' . $this->getProject()['name'] . ' password using the link.', $lastEmail['text']);

        $tokens = $this->extractQueryParamsFromEmailLink($lastEmail['html']);
        $secret = $tokens['secret'];

        $response = $this->client->call(Client::METHOD_PUT, '/account/recovery', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'userId' => $userId,
            'secret' => $secret,
            'password' => 'password',
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        return [
            'userId' => $userId,
            'membershipId' => $membershipId,
        ];
    }

    protected function updateMembershipRole(string $teamId, string $membershipId, array $roles): void
    {
        $response = $this->client->call(Client::METHOD_PATCH, '/teams/' . $teamId . '/memberships/' . $membershipId, array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'roles' => $roles,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
    }

    protected function setupFunction(string $projectId, string $functionId, string $token): void
    {
        $function = $this->client->call(Client::METHOD_POST, '/functions', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-mode' => 'admin',
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $token,
        ]), [
            'functionId' => $functionId,
            'name' => 'Test function',
            'execute' => [Role::any()->toString()],
            'runtime' => 'node-22',
            'entrypoint' => 'index.js',
            'events' => [
                'users.*.create',
                'users.*.delete',
            ],
            'schedule' => '0 0 1 1 *',
            'timeout' => 10,
        ]);
        $this->assertEquals(201, $function['headers']['status-code']);
        $this->assertNotEmpty($function['body']['$id']);
    }
}
