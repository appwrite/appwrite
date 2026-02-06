<?php

namespace Tests\E2E\Services\Users;

use Appwrite\Tests\Retry;
use Appwrite\Utopia\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\E2E\Client;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;

trait UsersBase
{
    /**
     * Static caches for test data
     */
    private static array $cachedUser = [];
    private static array $cachedHashedPasswordUsers = [];
    private static array $cachedUserTarget = [];
    private static bool $userNameUpdated = false;
    private static bool $userEmailUpdated = false;
    private static bool $userNumberUpdated = false;

    /**
     * Helper to get or create a base test user
     */
    protected function setupUser(): array
    {
        $projectId = $this->getProject()['$id'];
        if (!empty(static::$cachedUser[$projectId])) {
            return static::$cachedUser[$projectId];
        }

        $user = $this->client->call(Client::METHOD_POST, '/users', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'userId' => ID::unique(),
            'email' => 'cristiano.ronaldo@manchester-united.co.uk',
            'password' => 'password',
            'name' => 'Cristiano Ronaldo',
        ]);

        if ($user['headers']['status-code'] === 409) {
            // User already exists, fetch by searching
            $response = $this->client->call(Client::METHOD_GET, '/users', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
            ], $this->getHeaders()), [
                'search' => 'cristiano.ronaldo@manchester-united.co.uk',
            ]);

            if (!empty($response['body']['users'])) {
                static::$cachedUser[$projectId] = ['userId' => $response['body']['users'][0]['$id']];
                return static::$cachedUser[$projectId];
            }
        }

        if ($user['headers']['status-code'] === 201) {
            static::$cachedUser[$projectId] = ['userId' => $user['body']['$id']];
        }

        return static::$cachedUser[$projectId];
    }

    /**
     * Helper to create user1 (Lionel Messi)
     */
    protected function setupUser1(): void
    {
        $projectId = $this->getProject()['$id'];

        $res = $this->client->call(Client::METHOD_POST, '/users', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'userId' => ID::custom('user1'),
            'email' => 'lionel.messi@psg.fr',
            'password' => 'password',
            'name' => 'Lionel Messi',
        ]);

        // Ignore 409 conflict - user already exists
    }

    /**
     * Helper to create all hashed password users for testing
     */
    protected function setupHashedPasswordUsers(): void
    {
        $projectId = $this->getProject()['$id'];
        if (!empty(static::$cachedHashedPasswordUsers[$projectId])) {
            return;
        }

        // MD5 user
        $this->client->call(Client::METHOD_POST, '/users/md5', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'userId' => 'md5',
            'email' => 'md5@appwrite.io',
            'password' => '144fa7eaa4904e8ee120651997f70dcc', // appwrite
            'name' => 'MD5 User',
        ]);

        // Bcrypt user
        $this->client->call(Client::METHOD_POST, '/users/bcrypt', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'userId' => 'bcrypt',
            'email' => 'bcrypt@appwrite.io',
            'password' => '$2a$15$xX/myGbFU.ZSKHSi6EHdBOySTdYm8QxBLXmOPHrYMwV0mHRBBSBOq', // appwrite (15 rounds)
            'name' => 'Bcrypt User',
        ]);

        // Argon2 user
        $this->client->call(Client::METHOD_POST, '/users/argon2', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'userId' => 'argon2',
            'email' => 'argon2@appwrite.io',
            'password' => '$argon2i$v=19$m=20,t=3,p=2$YXBwd3JpdGU$A/54i238ed09ZR4NwlACU5XnkjNBZU9QeOEuhjLiexI', // appwrite (salt appwrite, parallel 2, memory 20, iterations 3, length 32)
            'name' => 'Argon2 User',
        ]);

        // SHA512 user
        $this->client->call(Client::METHOD_POST, '/users/sha', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'userId' => 'sha512',
            'email' => 'sha512@appwrite.io',
            'password' => '4243da0a694e8a2f727c8060fe0507c8fa01ca68146c76d2c190805b638c20c6bf6ba04e21f11ae138785d0bff63c416e6f87badbffad37f6dee50094cc38c70', // appwrite (sha512)
            'name' => 'SHA512 User',
            'passwordVersion' => 'sha512'
        ]);

        // Scrypt user
        $this->client->call(Client::METHOD_POST, '/users/scrypt', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'userId' => 'scrypt',
            'email' => 'scrypt@appwrite.io',
            'password' => '3fdef49701bc4cfaacd551fe017283513284b4731e6945c263246ef948d3cf63b5d269c31fd697246085111a428245e24a4ddc6b64c687bc60a8910dbafc1d5b', // appwrite (salt appwrite, cpu 16384, memory 13, parallel 2, length 64)
            'name' => 'Scrypt User',
            'passwordSalt' => 'appwrite',
            'passwordCpu' => 16384,
            'passwordMemory' => 13,
            'passwordParallel' => 2,
            'passwordLength' => 64
        ]);

        // PHPass user
        $this->client->call(Client::METHOD_POST, '/users/phpass', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'userId' => 'phpass',
            'email' => 'phpass@appwrite.io',
            'password' => '$P$Br387rwferoKN7uwHZqNMu98q3U8RO.',
            'name' => 'PHPass User',
        ]);

        // Scrypt Modified user
        $this->client->call(Client::METHOD_POST, '/users/scrypt-modified', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'userId' => 'scrypt-modified',
            'email' => 'scrypt-modified@appwrite.io',
            'password' => 'UlM7JiXRcQhzAGlaonpSqNSLIz475WMddOgLjej5De9vxTy48K6WtqlEzrRFeK4t0COfMhWCb8wuMHgxOFCHFQ==', // appwrite
            'name' => 'Scrypt Modified User',
            'passwordSalt' => 'UxLMreBr6tYyjQ==',
            'passwordSaltSeparator' => 'Bw==',
            'passwordSignerKey' => 'XyEKE9RcTDeLEsL/RjwPDBv/RqDl8fb3gpYEOQaPihbxf1ZAtSOHCjuAAa7Q3oHpCYhXSN9tizHgVOwn6krflQ==',
        ]);

        static::$cachedHashedPasswordUsers[$projectId] = true;
    }

    /**
     * Helper to create or get a user target
     */
    protected function setupUserTarget(): array
    {
        $projectId = $this->getProject()['$id'];
        if (!empty(static::$cachedUserTarget[$projectId])) {
            return static::$cachedUserTarget[$projectId];
        }

        $data = $this->setupUser();

        // Create provider
        $provider = $this->client->call(Client::METHOD_POST, '/messaging/providers/sendgrid', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'providerId' => ID::unique(),
            'name' => 'Sengrid1',
            'apiKey' => 'my-apikey',
            'from' => 'from@domain.com',
        ]);

        if ($provider['headers']['status-code'] !== 201) {
            // Provider may already exist, try to find it
            $providers = $this->client->call(Client::METHOD_GET, '/messaging/providers', \array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
            ], $this->getHeaders()));

            foreach ($providers['body']['providers'] ?? [] as $p) {
                if ($p['name'] === 'Sengrid1') {
                    $provider = ['body' => $p];
                    break;
                }
            }
        }

        // Create target
        $response = $this->client->call(Client::METHOD_POST, '/users/' . $data['userId'] . '/targets', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'targetId' => ID::unique(),
            'providerId' => $provider['body']['$id'],
            'providerType' => 'email',
            'identifier' => 'random-email@mail.org',
        ]);

        if ($response['headers']['status-code'] === 201) {
            static::$cachedUserTarget[$projectId] = $response['body'];
        }

        return static::$cachedUserTarget[$projectId] ?? [];
    }

    /**
     * Helper to ensure user name is updated (for search tests)
     */
    protected function ensureUserNameUpdated(): array
    {
        $data = $this->setupUser();
        $projectId = $this->getProject()['$id'];

        if (static::$userNameUpdated) {
            return $data;
        }

        $this->client->call(Client::METHOD_PATCH, '/users/' . $data['userId'] . '/name', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'name' => 'Updated name',
        ]);

        static::$userNameUpdated = true;
        return $data;
    }

    /**
     * Helper to ensure user email is updated (for search and password tests)
     */
    protected function ensureUserEmailUpdated(): array
    {
        $data = $this->setupUser();
        $projectId = $this->getProject()['$id'];

        if (static::$userEmailUpdated) {
            return $data;
        }

        $this->client->call(Client::METHOD_PATCH, '/users/' . $data['userId'] . '/email', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'email' => 'users.service@updated.com',
        ]);

        static::$userEmailUpdated = true;
        return $data;
    }

    /**
     * Helper to ensure user phone number is updated (for search tests)
     */
    protected function ensureUserNumberUpdated(): array
    {
        $data = $this->setupUser();
        $projectId = $this->getProject()['$id'];

        if (static::$userNumberUpdated) {
            return $data;
        }

        $this->client->call(Client::METHOD_PATCH, '/users/' . $data['userId'] . '/phone', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'number' => '+910000000000',
        ]);

        static::$userNumberUpdated = true;
        return $data;
    }

    public function testCreateUser(): void
    {
        /**
         * Test for SUCCESS
         */
        $user = $this->client->call(Client::METHOD_POST, '/users', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'userId' => ID::unique(),
            'email' => 'cristiano.ronaldo@manchester-united.co.uk',
            'password' => 'password',
            'name' => 'Cristiano Ronaldo',
        ], false);
        $this->assertEquals($user['headers']['status-code'], 201);

        // Test empty prefs is object not array
        $bodyString = $user['body'];
        $prefs = substr($bodyString, strpos($bodyString, '"prefs":') + 8, 2);
        $this->assertEquals('{}', $prefs);

        $body = json_decode($bodyString, true);

        $this->assertEquals($user['headers']['status-code'], 201);
        $this->assertEquals($body['name'], 'Cristiano Ronaldo');
        $this->assertEquals($body['email'], 'cristiano.ronaldo@manchester-united.co.uk');
        $this->assertEquals($body['status'], true);
        $this->assertGreaterThan('2000-01-01 00:00:00', $body['registration']);
        $this->assertEquals($body['labels'], []);

        /**
         * Test Create with Custom ID for SUCCESS
         */
        $res = $this->client->call(Client::METHOD_POST, '/users', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'userId' => ID::custom('user1'),
            'email' => 'lionel.messi@psg.fr',
            'password' => 'password',
            'name' => 'Lionel Messi',
        ]);

        $this->assertEquals($res['headers']['status-code'], 201);
        $this->assertEquals($res['body']['$id'], 'user1');
        $this->assertEquals($res['body']['name'], 'Lionel Messi');
        $this->assertEquals($res['body']['email'], 'lionel.messi@psg.fr');
        $this->assertEquals(true, $res['body']['status']);
        $this->assertGreaterThan('2000-01-01 00:00:00', $res['body']['registration']);

        /**
         * Test Create with hashed passwords
         */
        $res = $this->client->call(Client::METHOD_POST, '/users/md5', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'userId' => 'md5',
            'email' => 'md5@appwrite.io',
            'password' => '144fa7eaa4904e8ee120651997f70dcc', // appwrite
            'name' => 'MD5 User',
        ]);

        $res = $this->client->call(Client::METHOD_POST, '/users/bcrypt', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'userId' => 'bcrypt',
            'email' => 'bcrypt@appwrite.io',
            'password' => '$2a$15$xX/myGbFU.ZSKHSi6EHdBOySTdYm8QxBLXmOPHrYMwV0mHRBBSBOq', // appwrite (15 rounds)
            'name' => 'Bcrypt User',
        ]);

        $this->assertEquals($res['headers']['status-code'], 201);
        $this->assertEquals($res['body']['password'], '$2a$15$xX/myGbFU.ZSKHSi6EHdBOySTdYm8QxBLXmOPHrYMwV0mHRBBSBOq');
        $this->assertEquals($res['body']['hash'], 'bcrypt');

        $res = $this->client->call(Client::METHOD_POST, '/users/argon2', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'userId' => 'argon2',
            'email' => 'argon2@appwrite.io',
            'password' => '$argon2i$v=19$m=20,t=3,p=2$YXBwd3JpdGU$A/54i238ed09ZR4NwlACU5XnkjNBZU9QeOEuhjLiexI', // appwrite (salt appwrite, parallel 2, memory 20, iterations 3, length 32)
            'name' => 'Argon2 User',
        ]);

        $this->assertEquals($res['headers']['status-code'], 201);
        $this->assertEquals($res['body']['password'], '$argon2i$v=19$m=20,t=3,p=2$YXBwd3JpdGU$A/54i238ed09ZR4NwlACU5XnkjNBZU9QeOEuhjLiexI');
        $this->assertEquals($res['body']['hash'], 'argon2');

        $res = $this->client->call(Client::METHOD_POST, '/users/sha', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'userId' => 'sha512',
            'email' => 'sha512@appwrite.io',
            'password' => '4243da0a694e8a2f727c8060fe0507c8fa01ca68146c76d2c190805b638c20c6bf6ba04e21f11ae138785d0bff63c416e6f87badbffad37f6dee50094cc38c70', // appwrite (sha512)
            'name' => 'SHA512 User',
            'passwordVersion' => 'sha512'
        ]);

        $this->assertEquals($res['headers']['status-code'], 201);
        $this->assertEquals($res['body']['password'], '4243da0a694e8a2f727c8060fe0507c8fa01ca68146c76d2c190805b638c20c6bf6ba04e21f11ae138785d0bff63c416e6f87badbffad37f6dee50094cc38c70');
        $this->assertEquals($res['body']['hash'], 'sha');
        $this->assertEquals($res['body']['hashOptions']['version'], 'sha512');

        $res = $this->client->call(Client::METHOD_POST, '/users/scrypt', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'userId' => 'scrypt',
            'email' => 'scrypt@appwrite.io',
            'password' => '3fdef49701bc4cfaacd551fe017283513284b4731e6945c263246ef948d3cf63b5d269c31fd697246085111a428245e24a4ddc6b64c687bc60a8910dbafc1d5b', // appwrite (salt appwrite, cpu 16384, memory 13, parallel 2, length 64)
            'name' => 'Scrypt User',
            'passwordSalt' => 'appwrite',
            'passwordCpu' => 16384,
            'passwordMemory' => 13,
            'passwordParallel' => 2,
            'passwordLength' => 64
        ]);

        $this->assertEquals($res['headers']['status-code'], 201);
        $this->assertEquals($res['body']['password'], '3fdef49701bc4cfaacd551fe017283513284b4731e6945c263246ef948d3cf63b5d269c31fd697246085111a428245e24a4ddc6b64c687bc60a8910dbafc1d5b');
        $this->assertEquals($res['body']['hash'], 'scrypt');
        $this->assertEquals($res['body']['hashOptions']['salt'], 'appwrite');
        $this->assertEquals($res['body']['hashOptions']['costCpu'], 16384);
        $this->assertEquals($res['body']['hashOptions']['costMemory'], 13);
        $this->assertEquals($res['body']['hashOptions']['costParallel'], 2);
        $this->assertEquals($res['body']['hashOptions']['length'], 64);

        $res = $this->client->call(Client::METHOD_POST, '/users/phpass', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'userId' => 'phpass',
            'email' => 'phpass@appwrite.io',
            'password' => '$P$Br387rwferoKN7uwHZqNMu98q3U8RO.',
            'name' => 'PHPass User',
        ]);

        $this->assertEquals($res['headers']['status-code'], 201);
        $this->assertEquals($res['body']['password'], '$P$Br387rwferoKN7uwHZqNMu98q3U8RO.');

        $res = $this->client->call(Client::METHOD_POST, '/users/scrypt-modified', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'userId' => 'scrypt-modified',
            'email' => 'scrypt-modified@appwrite.io',
            'password' => 'UlM7JiXRcQhzAGlaonpSqNSLIz475WMddOgLjej5De9vxTy48K6WtqlEzrRFeK4t0COfMhWCb8wuMHgxOFCHFQ==', // appwrite
            'name' => 'Scrypt Modified User',
            'passwordSalt' => 'UxLMreBr6tYyjQ==',
            'passwordSaltSeparator' => 'Bw==',
            'passwordSignerKey' => 'XyEKE9RcTDeLEsL/RjwPDBv/RqDl8fb3gpYEOQaPihbxf1ZAtSOHCjuAAa7Q3oHpCYhXSN9tizHgVOwn6krflQ==',
        ]);

        $this->assertEquals($res['headers']['status-code'], 201);
        $this->assertEquals($res['body']['password'], 'UlM7JiXRcQhzAGlaonpSqNSLIz475WMddOgLjej5De9vxTy48K6WtqlEzrRFeK4t0COfMhWCb8wuMHgxOFCHFQ==');
        $this->assertEquals($res['body']['hash'], 'scryptMod');
        $this->assertEquals($res['body']['hashOptions']['salt'], 'UxLMreBr6tYyjQ==');
        $this->assertEquals($res['body']['hashOptions']['signerKey'], 'XyEKE9RcTDeLEsL/RjwPDBv/RqDl8fb3gpYEOQaPihbxf1ZAtSOHCjuAAa7Q3oHpCYhXSN9tizHgVOwn6krflQ==');
        $this->assertEquals($res['body']['hashOptions']['saltSeparator'], 'Bw==');

        // Cache the user ID for other tests
        $projectId = $this->getProject()['$id'];
        static::$cachedUser[$projectId] = ['userId' => $body['$id']];
    }

    /**
     * Tries to login into all accounts created with hashed password. Ensures hash veifying logic.
     */
    public function testCreateUserSessionHashed(): void
    {
        $this->setupHashedPasswordUsers();
        $userIds = ['md5', 'bcrypt', 'argon2', 'sha512', 'scrypt', 'phpass', 'scrypt-modified'];

        foreach ($userIds as $userId) {
            // Ensure sessions can be created with hashed passwords
            $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
                'origin' => 'http://localhost',
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ]), [
                'email' => $userId . '@appwrite.io',
                'password' => 'appwrite',
            ]);

            $this->assertEquals(201, $response['headers']['status-code']);
            $this->assertEquals($userId, $response['body']['userId']);
        }

        foreach ($userIds as $userId) {
            // Ensure all passwords were re-hashed
            $response = $this->client->call(Client::METHOD_GET, '/users/' . $userId, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()), []);

            $this->assertEquals(200, $response['headers']['status-code']);
            $this->assertEquals($userId, $response['body']['$id']);
            $this->assertEquals($userId . '@appwrite.io', $response['body']['email']);
            $this->assertEquals('argon2', $response['body']['hash']);
            $this->assertStringStartsWith('$argon2', $response['body']['password']);
        }

        foreach ($userIds as $userId) {
            // Ensure sessions can be created after re-hashing of passwords
            $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
                'origin' => 'http://localhost',
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ]), [
                'email' => $userId . '@appwrite.io',
                'password' => 'appwrite',
            ]);

            $this->assertEquals(201, $response['headers']['status-code']);
            $this->assertEquals($userId, $response['body']['userId']);
        }
    }

    public function testCreateToken(): void
    {
        $data = $this->setupUser();

        /**
         * Test for SUCCESS
         */
        $token = $this->client->call(Client::METHOD_POST, '/users/' . $data['userId'] . '/tokens', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(201, $token['headers']['status-code']);
        $this->assertEquals($data['userId'], $token['body']['userId']);
        $this->assertNotEmpty($token['body']['secret']);
        $this->assertNotEmpty($token['body']['expire']);

        $token = $this->client->call(Client::METHOD_POST, '/users/' . $data['userId'] . '/tokens', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'length' => 15,
            'expire' => 60,
        ]);

        $this->assertEquals(201, $token['headers']['status-code']);
        $this->assertEquals($data['userId'], $token['body']['userId']);
        $this->assertEquals(15, strlen($token['body']['secret']));
        $this->assertNotEmpty($token['body']['expire']);

        /**
         * Test for FAILURE
         */
        $token = $this->client->call(Client::METHOD_POST, '/users/' . $data['userId'] . '/tokens', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'length' => 1,
            'expire' => 1,
        ]);

        $this->assertEquals(400, $token['headers']['status-code']);
        $this->assertArrayNotHasKey('userId', $token['body']);
        $this->assertArrayNotHasKey('secret', $token['body']);

        $token = $this->client->call(Client::METHOD_POST, '/users/' . $data['userId'] . '/tokens', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'expire' => 999999999999999,
        ]);

        $this->assertEquals(400, $token['headers']['status-code']);
        $this->assertArrayNotHasKey('userId', $token['body']);
        $this->assertArrayNotHasKey('secret', $token['body']);
    }

    public function testCreateSession(): void
    {
        $data = $this->setupUser();

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_POST, '/users/' . $data['userId'] . '/sessions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(201, $response['headers']['status-code']);

        $session = $response['body'];
        $this->assertEquals($data['userId'], $session['userId']);
        $this->assertNotEmpty($session['secret']);
        $this->assertNotEmpty($session['expire']);
        $this->assertEquals('server', $session['provider']);

        $response = $this->client->call(Client::METHOD_GET, '/account', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-session' => $session['secret']
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_DELETE, '/account/sessions/current', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-session' => $session['secret']
        ]);

        $this->assertEquals(204, $response['headers']['status-code']);
    }


    /**
     * Tests all optional parameters of createUser (email, phone, anonymous..)
     */
    public function testCreateUserTypes(): void
    {
        /**
         * Test for SUCCESS
         */

        // Email + password
        $response = $this->client->call(Client::METHOD_POST, '/users', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'userId' => 'unique()',
            'email' => 'emailuser@appwrite.io',
            'password' => 'emailUserPassword',
        ]);

        $this->assertNotEmpty($response['body']['email']);
        $this->assertNotEmpty($response['body']['password']);
        $this->assertEmpty($response['body']['phone']);

        // Phone
        $response = $this->client->call(Client::METHOD_POST, '/users', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'userId' => 'unique()',
            'phone' => '+123456789012',
        ]);

        $this->assertEmpty($response['body']['email']);
        $this->assertEmpty($response['body']['password']);
        $this->assertNotEmpty($response['body']['phone']);

        // Anonymous
        $response = $this->client->call(Client::METHOD_POST, '/users', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'userId' => 'unique()',
        ]);

        $this->assertEmpty($response['body']['email']);
        $this->assertEmpty($response['body']['password']);
        $this->assertEmpty($response['body']['phone']);

        // Email-only
        $response = $this->client->call(Client::METHOD_POST, '/users', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'userId' => 'unique()',
            'email' => 'emailonlyuser@appwrite.io',
        ]);

        $this->assertNotEmpty($response['body']['email']);
        $this->assertEmpty($response['body']['password']);
        $this->assertEmpty($response['body']['phone']);

        // Password-only
        $response = $this->client->call(Client::METHOD_POST, '/users', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'userId' => 'unique()',
            'password' => 'passwordOnlyUser',
        ]);

        $this->assertEmpty($response['body']['email']);
        $this->assertNotEmpty($response['body']['password']);
        $this->assertEmpty($response['body']['phone']);

        // Password and phone
        $response = $this->client->call(Client::METHOD_POST, '/users', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'userId' => 'unique()',
            'password' => 'passwordOnlyUser',
            'phone' => '+123456789013',
        ]);

        $this->assertEmpty($response['body']['email']);
        $this->assertNotEmpty($response['body']['password']);
        $this->assertNotEmpty($response['body']['phone']);
    }

    public function testListUsers(): void
    {
        $data = $this->setupUser();
        $this->setupUser1();
        $this->setupHashedPasswordUsers();
        // In --functional mode, this test runs independently with 9 users created above
        // (setupUser: 1 + setupUser1: 1 + setupHashedPasswordUsers: 7)
        // In sequential mode, there may be more users from other tests
        $minUsers = 9;

        /**
         * Test for SUCCESS listUsers
         */
        $response = $this->client->call(Client::METHOD_GET, '/users', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['users']);
        $this->assertGreaterThanOrEqual($minUsers, count($response['body']['users']));

        // Find our users by ID instead of assuming position
        $userIds = array_column($response['body']['users'], '$id');
        $this->assertContains($data['userId'], $userIds);
        $this->assertContains('user1', $userIds);

        // Find user1 for later use in queries
        $user1 = null;
        foreach ($response['body']['users'] as $user) {
            if ($user['$id'] === 'user1') {
                $user1 = $user;
                break;
            }
        }
        $this->assertNotNull($user1, 'user1 should exist in user list');

        // This test ensures that by default, endpoints dont support select queries
        // If we add select query to this endpoint, you will need to remove this test
        // Please make sure to add it to another place, unless all endpoints support select queries
        $response = $this->client->call(Client::METHOD_GET, '/users', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::select(['name'])->toString()
            ]
        ]);
        $this->assertEquals($response['headers']['status-code'], 400);

        $response = $this->client->call(Client::METHOD_GET, '/users', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::equal('name', [$user1['name']])->toString()
            ]
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['users']);
        $this->assertCount(1, $response['body']['users']);
        $this->assertEquals($response['body']['users'][0]['name'], $user1['name']);


        $response = $this->client->call(Client::METHOD_GET, '/users', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::equal('name', [$user1['name']])->toString()
            ]
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['users']);
        $this->assertCount(1, $response['body']['users']);
        $this->assertEquals($response['body']['users'][0]['email'], $user1['email']);

        $response = $this->client->call(Client::METHOD_GET, '/users', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::equal('status', [true])->toString()
            ]
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['users']);
        // In parallel mode, count may vary - just ensure our known users are present
        $this->assertGreaterThanOrEqual($minUsers, count($response['body']['users']));
        // Verify our test users are in the results by ID
        $userIds = array_column($response['body']['users'], '$id');
        $this->assertContains($data['userId'], $userIds);
        $this->assertContains('user1', $userIds);

        $response = $this->client->call(Client::METHOD_GET, '/users', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::equal('status', [false])->toString()
            ]
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertNotEmpty($response['body']);
        $this->assertEmpty($response['body']['users']);
        $this->assertCount(0, $response['body']['users']);

        $response = $this->client->call(Client::METHOD_GET, '/users', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::equal('passwordUpdate', [$user1['passwordUpdate']])->toString()
            ]
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['users']);
        $this->assertCount(1, $response['body']['users']);
        $this->assertEquals($response['body']['users'][0]['passwordUpdate'], $user1['passwordUpdate']);

        $response = $this->client->call(Client::METHOD_GET, '/users', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::equal('registration', [$user1['registration']])->toString()
            ]
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['users']);
        $this->assertCount(1, $response['body']['users']);
        $this->assertEquals($response['body']['users'][0]['registration'], $user1['registration']);

        $response = $this->client->call(Client::METHOD_GET, '/users', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::equal('emailVerification', [false])->toString()
            ]
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['users']);
        // In parallel mode, count may vary - just ensure our known users are present
        $this->assertGreaterThanOrEqual($minUsers, count($response['body']['users']));
        // Verify our test users are in the results by ID
        $userIds = array_column($response['body']['users'], '$id');
        $this->assertContains($data['userId'], $userIds);
        $this->assertContains('user1', $userIds);

        $response = $this->client->call(Client::METHOD_GET, '/users', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::equal('emailVerification', [true])->toString()
            ]
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertNotEmpty($response['body']);
        $this->assertEmpty($response['body']['users']);
        $this->assertCount(0, $response['body']['users']);

        $response = $this->client->call(Client::METHOD_GET, '/users', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::equal('phoneVerification', [false])->toString()
            ]
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertNotEmpty($response['body']);
        $this->assertIsArray($response['body']['users']);
        $this->assertGreaterThanOrEqual($minUsers, count($response['body']['users']));

        $response = $this->client->call(Client::METHOD_GET, '/users', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::equal('phoneVerification', [true])->toString()
            ]
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertNotEmpty($response['body']);
        $this->assertEmpty($response['body']['users']);
        $this->assertCount(0, $response['body']['users']);

        $response = $this->client->call(Client::METHOD_GET, '/users', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::cursorAfter(new Document(['$id' => $data['userId']]))->toString()
            ]
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['users']);
        // CursorAfter should return results, count varies in parallel mode
        $this->assertGreaterThanOrEqual(1, count($response['body']['users']));
        // First result after cursor should be user1 (created right after setupUser)
        $this->assertEquals($response['body']['users'][0]['$id'], 'user1');

        $response = $this->client->call(Client::METHOD_GET, '/users', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::cursorBefore(new Document(['$id' => 'user1']))->toString()
            ]
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertNotEmpty($response['body']['users']);
        $this->assertCount(1, $response['body']['users']);

        $this->assertEquals($response['body']['users'][0]['$id'], $data['userId']);

        /**
         * Test for SUCCESS searchUsers
         */
        $response = $this->client->call(Client::METHOD_GET, '/users', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'search' => "Ronaldo",
        ]);
        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['users']);
        $this->assertCount(1, $response['body']['users']);
        $this->assertEquals($response['body']['users'][0]['$id'], $data['userId']);

        $response = $this->client->call(Client::METHOD_GET, '/users', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'search' => "cristiano.ronaldo@manchester-united.co.uk",
        ]);
        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['users']);
        $this->assertCount(1, $response['body']['users']);
        $this->assertEquals($response['body']['users'][0]['$id'], $data['userId']);

        $response = $this->client->call(Client::METHOD_GET, '/users', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'search' => "cristiano.ronaldo",
        ]);
        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['users']);
        $this->assertCount(1, $response['body']['users']);
        $this->assertEquals($response['body']['users'][0]['$id'], $data['userId']);

        $response = $this->client->call(Client::METHOD_GET, '/users', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'search' => "manchester",
        ]);
        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['users']);
        $this->assertCount(1, $response['body']['users']);
        $this->assertEquals($response['body']['users'][0]['$id'], $data['userId']);

        $response = $this->client->call(Client::METHOD_GET, '/users', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'search' => "united.co.uk",
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertIsArray($response['body']);
        $this->assertIsArray($response['body']['users']);
        $this->assertIsInt($response['body']['total']);
        $this->assertEquals(1, $response['body']['total']);
        $this->assertCount(1, $response['body']['users']);

        $response = $this->client->call(Client::METHOD_GET, '/users', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'search' => "man",
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertIsArray($response['body']);
        $this->assertIsArray($response['body']['users']);
        $this->assertIsInt($response['body']['total']);
        $this->assertEquals(1, $response['body']['total']);
        $this->assertCount(1, $response['body']['users']);

        $response = $this->client->call(Client::METHOD_GET, '/users', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'search' => $data['userId'],
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['users']);
        $this->assertCount(1, $response['body']['users']);
        $this->assertEquals($response['body']['users'][0]['$id'], $data['userId']);

        $response = $this->client->call(Client::METHOD_GET, '/users', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'search' => '>',
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertNotEmpty($response['body']);
        $this->assertEmpty($response['body']['users']);
        $this->assertCount(0, $response['body']['users']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_GET, '/users', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::cursorAfter(new Document(['$id' => 'unknown']))->toString()
            ]
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);
    }

    public function testGetUser(): void
    {
        $data = $this->setupUser();

        /**
         * Test for SUCCESS
         */
        $user = $this->client->call(Client::METHOD_GET, '/users/' . $data['userId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($user['headers']['status-code'], 200);
        $this->assertNotEmpty($user['body']['name']);
        $this->assertNotEmpty($user['body']['email']);
        $this->assertEquals($user['body']['status'], true);
        $this->assertGreaterThan('2000-01-01 00:00:00', $user['body']['registration']);

        $sessions = $this->client->call(Client::METHOD_GET, '/users/' . $data['userId'] . '/sessions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($sessions['headers']['status-code'], 200);
        $this->assertIsArray($sessions['body']);

        $users = $this->client->call(Client::METHOD_GET, '/users', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($users['headers']['status-code'], 200);
        $this->assertIsArray($users['body']);
        $this->assertIsArray($users['body']['users']);
        $this->assertIsInt($users['body']['total']);
        $this->assertGreaterThan(0, $users['body']['total']);

        /**
         * Test for SUCCESS with total=false
         */
        $usersWithIncludeTotalFalse = $this->client->call(Client::METHOD_GET, '/users', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'total' => false
        ]);

        $this->assertEquals(200, $usersWithIncludeTotalFalse['headers']['status-code']);
        $this->assertIsArray($usersWithIncludeTotalFalse['body']);
        $this->assertIsArray($usersWithIncludeTotalFalse['body']['users']);
        $this->assertIsInt($usersWithIncludeTotalFalse['body']['total']);
        $this->assertEquals(0, $usersWithIncludeTotalFalse['body']['total']);
        $this->assertGreaterThan(0, count($usersWithIncludeTotalFalse['body']['users']));

        /**
         * Test for FAILURE
         */
        $user = $this->client->call(Client::METHOD_GET, '/users/non_existent', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($user['headers']['status-code'], 404);
        $this->assertEquals($user['body']['code'], 404);
        $this->assertEquals($user['body']['message'], 'User with the requested ID could not be found.');
        $this->assertEquals($user['body']['type'], 'user_not_found');
    }

    public function testListUserMemberships(): void
    {
        $data = $this->setupUser();
        /**
         * Test for SUCCESS
         */

        // create a new team
        $team = $this->client->call(Client::METHOD_POST, '/teams', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'teamId' => 'unique()',
            'name' => 'Test Team',
        ]);

        // create a new membership
        $membership = $this->client->call(Client::METHOD_POST, '/teams/' . $team['body']['$id'] . '/memberships', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'userId' => $data['userId'],
            'roles' => ['new-role'],
        ]);

        // list the memberships
        $response = $this->client->call(Client::METHOD_GET, '/users/' . $data['userId'] . '/memberships', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertEquals($response['body']['memberships'][0]['$id'], $membership['body']['$id']);
        $this->assertEquals($response['body']['memberships'][0]['roles'], ['new-role']);
        $this->assertEquals($response['body']['total'], 1);

        // create another membership with a new role
        $team = $this->client->call(Client::METHOD_POST, '/teams', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'teamId' => 'unique()',
            'name' => 'Test Team 2',
        ]);

        $membership = $this->client->call(Client::METHOD_POST, '/teams/' . $team['body']['$id'] . '/memberships', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'userId' => $data['userId'],
            'roles' => ['new-role-2'],
        ]);

        // list out memberships and query by role
        $response = $this->client->call(Client::METHOD_GET, '/users/' . $data['userId'] . '/memberships', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::contains('roles', ['new-role-2'])->toString()
            ]
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertEquals($response['body']['memberships'][0]['$id'], $membership['body']['$id']);
        $this->assertEquals($response['body']['memberships'][0]['roles'], ['new-role-2']);
        $this->assertEquals($response['body']['total'], 1);

        /**
         * Test for FAILURE
         */

        // query using equal on array field
        $response = $this->client->call(Client::METHOD_GET, '/users/' . $data['userId'] . '/memberships', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::equal('roles', ['new-role-2'])->toString()
            ]
        ]);

        $this->assertEquals($response['body']['code'], 400);
        $this->assertEquals($response['body']['message'], 'Invalid `queries` param: Invalid query: Cannot query equal on attribute "roles" because it is an array.');
        $this->assertEquals($response['body']['type'], 'general_argument_invalid');
    }

    public function testUpdateUserName(): void
    {
        $data = $this->setupUser();

        /**
         * Test for SUCCESS
         */
        $user = $this->client->call(Client::METHOD_PATCH, '/users/' . $data['userId'] . '/name', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => '',
        ]);

        $this->assertEquals($user['headers']['status-code'], 200);
        $this->assertEquals($user['body']['name'], '');

        $user = $this->client->call(Client::METHOD_GET, '/users/' . $data['userId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($user['headers']['status-code'], 200);
        $this->assertEquals($user['body']['name'], '');

        $user = $this->client->call(Client::METHOD_PATCH, '/users/' . $data['userId'] . '/name', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Updated name',
        ]);

        $this->assertEquals($user['headers']['status-code'], 200);
        $this->assertEquals($user['body']['name'], 'Updated name');

        $user = $this->client->call(Client::METHOD_GET, '/users/' . $data['userId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($user['headers']['status-code'], 200);
        $this->assertEquals($user['body']['name'], 'Updated name');

        // Mark name as updated for search tests
        static::$userNameUpdated = true;
    }

    public function testUpdateUserNameSearch(): void
    {
        $data = $this->ensureUserNameUpdated();
        $id = $data['userId'] ?? '';
        $newName = 'Updated name';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/users', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'search' => $newName,
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['users']);
        $this->assertCount(1, $response['body']['users']);
        $this->assertEquals($response['body']['users'][0]['$id'], $id);

        $response = $this->client->call(Client::METHOD_GET, '/users', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'search' => $id,
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['users']);
        $this->assertCount(1, $response['body']['users']);
        $this->assertEquals($response['body']['users'][0]['$id'], $id);
    }

    public function testUpdateUserEmail(): void
    {
        $data = $this->setupUser();

        /**
         * Test for SUCCESS
         */
        $user = $this->client->call(Client::METHOD_PATCH, '/users/' . $data['userId'] . '/email', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'email' => '',
        ]);

        $this->assertEquals($user['headers']['status-code'], 200);
        $this->assertEquals($user['body']['email'], '');

        $user = $this->client->call(Client::METHOD_GET, '/users/' . $data['userId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($user['headers']['status-code'], 200);
        $this->assertEquals($user['body']['email'], '');

        $user = $this->client->call(Client::METHOD_PATCH, '/users/' . $data['userId'] . '/email', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'email' => 'users.service@updated.com',
        ]);

        $this->assertEquals($user['headers']['status-code'], 200);
        $this->assertEquals($user['body']['email'], 'users.service@updated.com');

        $user = $this->client->call(Client::METHOD_GET, '/users/' . $data['userId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($user['headers']['status-code'], 200);
        $this->assertEquals($user['body']['email'], 'users.service@updated.com');

        // Mark email as updated for search tests
        static::$userEmailUpdated = true;
    }

    public function testUpdateUserEmailSearch(): void
    {
        $data = $this->ensureUserEmailUpdated();
        $id = $data['userId'] ?? '';
        $newEmail = '"users.service@updated.com"';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/users', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'search' => $newEmail,
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['users']);
        $this->assertCount(1, $response['body']['users']);
        $this->assertEquals($response['body']['users'][0]['$id'], $id);

        $response = $this->client->call(Client::METHOD_GET, '/users', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'search' => $id,
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['users']);
        $this->assertCount(1, $response['body']['users']);
        $this->assertEquals($response['body']['users'][0]['$id'], $id);
    }

    public function testUpdateUserPassword(): void
    {
        $data = $this->ensureUserEmailUpdated();

        /**
         * Test for SUCCESS
         */
        $session = $this->client->call(Client::METHOD_POST, '/account/sessions/email', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'email' => 'users.service@updated.com',
            'password' => 'password'
        ]);

        $this->assertEquals($session['headers']['status-code'], 201);

        $user = $this->client->call(Client::METHOD_PATCH, '/users/' . $data['userId'] . '/password', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'password' => '',
        ]);

        $this->assertEquals($user['headers']['status-code'], 200);
        $this->assertNotEmpty($user['body']['$id']);
        $this->assertEmpty($user['body']['password']);
        sleep(5);

        $session = $this->client->call(Client::METHOD_POST, '/account/sessions/email', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'email' => 'users.service@updated.com',
            'password' => 'password'
        ]);

        $this->assertEquals(401, $session['headers']['status-code']);
        $this->updateProjectinvalidateSessionsProperty(true);
        $user = $this->client->call(Client::METHOD_PATCH, '/users/' . $data['userId'] . '/password', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'password' => 'password2',
        ]);

        $this->assertEquals($user['headers']['status-code'], 200);
        $this->assertNotEmpty($user['body']['$id']);
        $this->assertNotEmpty($user['body']['password']);

        $sessions = $this->client->call(Client::METHOD_GET, '/users/' . $data['userId'] . '/sessions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($sessions['headers']['status-code'], 200);
        $this->assertIsArray($sessions['body']);
        $this->assertEmpty($sessions['body']['sessions']);

        $session = $this->client->call(Client::METHOD_POST, '/account/sessions/email', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'email' => 'users.service@updated.com',
            'password' => 'password2'
        ]);

        $this->assertEquals($session['headers']['status-code'], 201);
        $this->updateProjectinvalidateSessionsProperty(false);
    }

    #[Retry(count: 1)]
    public function testUpdateUserStatus(): void
    {
        $data = $this->setupUser();
        /**
         * Test for SUCCESS
         */
        $user = $this->client->call(Client::METHOD_PATCH, '/users/' . $data['userId'] . '/status', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'status' => false,
        ]);

        $this->assertEquals($user['headers']['status-code'], 200);
        $this->assertEquals($user['body']['status'], false);

        $user = $this->client->call(Client::METHOD_GET, '/users/' . $data['userId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($user['headers']['status-code'], 200);
        $this->assertEquals($user['body']['status'], false);

        // Reset status back to true for other tests
        $this->client->call(Client::METHOD_PATCH, '/users/' . $data['userId'] . '/status', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'status' => true,
        ]);
    }

    public function testUpdateEmailVerification(): void
    {
        $data = $this->setupUser();
        /**
         * Test for SUCCESS
         */
        $user = $this->client->call(Client::METHOD_PATCH, '/users/' . $data['userId'] . '/verification', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'emailVerification' => true,
        ]);

        $this->assertEquals($user['headers']['status-code'], 200);
        $this->assertEquals($user['body']['emailVerification'], true);

        $user = $this->client->call(Client::METHOD_GET, '/users/' . $data['userId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($user['headers']['status-code'], 200);
        $this->assertEquals($user['body']['emailVerification'], true);
    }

    #[Retry(count: 1)]
    public function testUpdateAndGetUserPrefs(): void
    {
        $data = $this->setupUser();
        /**
         * Test for SUCCESS
         */
        $user = $this->client->call(Client::METHOD_PATCH, '/users/' . $data['userId'] . '/prefs', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'prefs' => [
                'funcKey1' => 'funcValue1',
                'funcKey2' => 'funcValue2',
            ],
        ]);

        $this->assertEquals($user['headers']['status-code'], 200);
        $this->assertEquals($user['body']['funcKey1'], 'funcValue1');
        $this->assertEquals($user['body']['funcKey2'], 'funcValue2');

        $user = $this->client->call(Client::METHOD_GET, '/users/' . $data['userId'] . '/prefs', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($user['headers']['status-code'], 200);
        $this->assertEquals($user['body'], [
            'funcKey1' => 'funcValue1',
            'funcKey2' => 'funcValue2',
        ]);

        /**
         * Test for FAILURE
         */
        $user = $this->client->call(Client::METHOD_PATCH, '/users/' . $data['userId'] . '/prefs', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'prefs' => 'bad-string',
        ]);

        $this->assertEquals($user['headers']['status-code'], 400);

        $user = $this->client->call(Client::METHOD_PATCH, '/users/' . $data['userId'] . '/prefs', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($user['headers']['status-code'], 400);
    }

    public function testUpdateUserNumber(): void
    {
        $data = $this->setupUser();
        $this->setupUser1();

        /**
         * Test for SUCCESS
         */
        $updatedNumber = "";
        $user = $this->client->call(Client::METHOD_PATCH, '/users/' . $data['userId'] . '/phone', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'number' => $updatedNumber,
        ]);

        $this->assertEquals($user['headers']['status-code'], 200);
        $this->assertEquals($user['body']['phone'], $updatedNumber);

        $user = $this->client->call(Client::METHOD_GET, '/users/' . $data['userId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($user['headers']['status-code'], 200);
        $this->assertEquals($user['body']['phone'], $updatedNumber);

        $updatedNumber = "+910000000000"; //dummy number
        $user = $this->client->call(Client::METHOD_PATCH, '/users/' . $data['userId'] . '/phone', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'number' => $updatedNumber,
        ]);

        $this->assertEquals($user['headers']['status-code'], 200);
        $this->assertEquals($user['body']['phone'], $updatedNumber);

        $user = $this->client->call(Client::METHOD_GET, '/users/' . $data['userId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($user['headers']['status-code'], 200);
        $this->assertEquals($user['body']['phone'], $updatedNumber);

        /**
         * Test for FAILURE
         */

        $errorType = "user_target_already_exists";
        $user1Id = "user1";
        $statusCodeForUserPhoneAlredyExists = 409;

        // adding same number ($updatedNumber) to different user i.e user1
        $response = $this->client->call(Client::METHOD_PATCH, '/users/' . $user1Id . '/phone', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'number' => $updatedNumber,
        ]);
        $this->assertEquals($response['headers']['status-code'], $statusCodeForUserPhoneAlredyExists);
        $this->assertNotEmpty($response['body']);
        $this->assertEquals($response['body']['type'], $errorType);

        // Mark phone as updated for search tests
        static::$userNumberUpdated = true;
    }

    public function testUpdateUserNumberSearch(): void
    {
        $data = $this->ensureUserNumberUpdated();
        $id = $data['userId'] ?? '';
        $newNumber = "+910000000000"; //dummy number

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/users', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'search' => $newNumber,
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['users']);
        $this->assertCount(1, $response['body']['users']);
        $this->assertEquals($response['body']['users'][0]['$id'], $id);
        $this->assertEquals($response['body']['users'][0]['phone'], $newNumber);
    }

    public static function userLabelsProvider(): array
    {
        return [
            'single label' => [
                ['admin'],
                Response::STATUS_CODE_OK,
                ['admin'],
            ],
            'replace with multiple labels' => [
                ['vip', 'pro'],
                Response::STATUS_CODE_OK,
                ['vip', 'pro'],
            ],
            'clear labels' => [
                [],
                Response::STATUS_CODE_OK,
                [],
            ],
            'duplicate labels' => [
                ['vip', 'vip', 'pro'],
                Response::STATUS_CODE_OK,
                ['vip', 'pro'],
            ],
            'invalid label' => [
                ['invalid-label'],
                Response::STATUS_CODE_BAD_REQUEST,
                [],
            ],
            'too long' => [
                [\str_repeat('a', 129)],
                Response::STATUS_CODE_BAD_REQUEST,
                [],
            ],
            'too many labels' => [
                [\array_fill(0, 101, 'a')],
                Response::STATUS_CODE_BAD_REQUEST,
                [],
            ],
        ];
    }

    #[DataProvider('userLabelsProvider')]
    public function testUpdateUserLabels(array $labels, int $expectedStatus, array $expectedLabels): void
    {
        $data = $this->setupUser();

        $user = $this->client->call(Client::METHOD_PUT, '/users/' . $data['userId'] . '/labels', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'labels' => $labels,
        ]);

        $this->assertEquals($expectedStatus, $user['headers']['status-code']);
        if ($expectedStatus === Response::STATUS_CODE_OK) {
            $this->assertEquals($user['body']['labels'], $expectedLabels);
        }
    }

    public function testUpdateUserLabelsWithoutLabels(): void
    {
        $data = $this->setupUser();

        $user = $this->client->call(Client::METHOD_PUT, '/users/' . $data['userId'] . '/labels', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(Response::STATUS_CODE_BAD_REQUEST, $user['headers']['status-code']);
    }

    public function testUpdateUserLabelsNonExistentUser(): void
    {
        $user = $this->client->call(Client::METHOD_PUT, '/users/dne/labels', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'labels' => ['admin'],
        ]);

        $this->assertEquals(Response::STATUS_CODE_NOT_FOUND, $user['headers']['status-code']);
    }


    public function testGetLogs(): void
    {
        $data = $this->setupUser();

        /**
         * Test for SUCCESS
         */
        $logs = $this->client->call(Client::METHOD_GET, '/users/' . $data['userId'] . '/logs', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($logs['headers']['status-code'], 200);
        $this->assertIsArray($logs['body']['logs']);
        $this->assertIsNumeric($logs['body']['total']);

        $logs = $this->client->call(Client::METHOD_GET, '/users/' . $data['userId'] . '/logs', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::limit(1)->toString()
            ],
        ]);

        $this->assertEquals($logs['headers']['status-code'], 200);
        $this->assertIsArray($logs['body']['logs']);
        $this->assertLessThanOrEqual(1, count($logs['body']['logs']));
        $this->assertIsNumeric($logs['body']['total']);

        $logs = $this->client->call(Client::METHOD_GET, '/users/' . $data['userId'] . '/logs', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::offset(1)->toString()
            ],
        ]);

        $this->assertEquals($logs['headers']['status-code'], 200);
        $this->assertIsArray($logs['body']['logs']);
        $this->assertIsNumeric($logs['body']['total']);

        $logs = $this->client->call(Client::METHOD_GET, '/users/' . $data['userId'] . '/logs', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::limit(1)->toString(),
                Query::offset(1)->toString(),
            ],
        ]);

        $this->assertEquals($logs['headers']['status-code'], 200);
        $this->assertIsArray($logs['body']['logs']);
        $this->assertLessThanOrEqual(1, count($logs['body']['logs']));
        $this->assertIsNumeric($logs['body']['total']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_GET, '/users/' . $data['userId'] . '/logs', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::limit(-1)->toString()
            ]
        ]);

        $this->assertEquals($response['headers']['status-code'], 400);

        $response = $this->client->call(Client::METHOD_GET, '/users/' . $data['userId'] . '/logs', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::offset(-1)->toString()
            ]
        ]);

        $this->assertEquals($response['headers']['status-code'], 400);

        $response = $this->client->call(Client::METHOD_GET, '/users/' . $data['userId'] . '/logs', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::equal('$id', ['asdf'])->toString()
            ]
        ]);

        $this->assertEquals($response['headers']['status-code'], 400);

        $response = $this->client->call(Client::METHOD_GET, '/users/' . $data['userId'] . '/logs', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::orderAsc('$id')->toString()
            ]
        ]);

        $this->assertEquals($response['headers']['status-code'], 400);

        $response = $this->client->call(Client::METHOD_GET, '/users/' . $data['userId'] . '/logs', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                '{ "method": "cursorAsc", "attribute": "$id" }'
            ]
        ]);

        $this->assertEquals($response['headers']['status-code'], 400);
    }

    public function testCreateUserTarget(): void
    {
        $data = $this->setupUser();

        $provider = $this->client->call(Client::METHOD_POST, '/messaging/providers/sendgrid', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'providerId' => ID::unique(),
            'name' => 'Sengrid1',
            'apiKey' => 'my-apikey',
            'from' => 'from@domain.com',
        ]);
        $this->assertEquals(201, $provider['headers']['status-code']);
        $response = $this->client->call(Client::METHOD_POST, '/users/' . $data['userId'] . '/targets', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'targetId' => ID::unique(),
            'providerId' => $provider['body']['$id'],
            'providerType' => 'email',
            'identifier' => 'random-email@mail.org',
        ]);
        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertEquals($provider['body']['$id'], $response['body']['providerId']);
        $this->assertEquals('random-email@mail.org', $response['body']['identifier']);

        // Cache for other tests
        $projectId = $this->getProject()['$id'];
        static::$cachedUserTarget[$projectId] = $response['body'];
    }

    public function testUpdateUserTarget(): void
    {
        $data = $this->setupUserTarget();

        $response = $this->client->call(Client::METHOD_PATCH, '/users/' . $data['userId'] . '/targets/' . $data['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'identifier' => 'random-email1@mail.org',
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('random-email1@mail.org', $response['body']['identifier']);
        $this->assertEquals(false, $response['body']['expired']);

        // Update cache with new data
        $projectId = $this->getProject()['$id'];
        static::$cachedUserTarget[$projectId] = $response['body'];
    }

    public function testListUserTarget(): void
    {
        $data = $this->setupUserTarget();

        $response = $this->client->call(Client::METHOD_GET, '/users/' . $data['userId'] . '/targets', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertGreaterThanOrEqual(1, \count($response['body']['targets']));
    }

    public function testGetUserTarget(): void
    {
        $data = $this->setupUserTarget();

        $response = $this->client->call(Client::METHOD_GET, '/users/' . $data['userId'] . '/targets/' . $data['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals($data['$id'], $response['body']['$id']);
    }

    public function testDeleteUserTarget(): void
    {
        $data = $this->setupUserTarget();

        $response = $this->client->call(Client::METHOD_DELETE, '/users/' . $data['userId'] . '/targets/' . $data['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(204, $response['headers']['status-code']);

        // Clear cached target since it was deleted
        $projectId = $this->getProject()['$id'];
        unset(static::$cachedUserTarget[$projectId]);

        $response = $this->client->call(Client::METHOD_GET, '/users/' . $data['userId'] . '/targets', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
    }

    public function testDeleteUser(): void
    {
        // Create a new user specifically for deletion test
        $userId = ID::unique();
        $user = $this->client->call(Client::METHOD_POST, '/users', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'userId' => $userId,
            'email' => 'deletetest@example.com',
            'password' => 'password',
            'name' => 'Delete Test User',
        ]);

        $this->assertEquals(201, $user['headers']['status-code']);

        /**
         * Test for SUCCESS
         */
        $user = $this->client->call(Client::METHOD_DELETE, '/users/' . $userId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($user['headers']['status-code'], 204);

        /**
         * Test for FAILURE
         */
        $user = $this->client->call(Client::METHOD_DELETE, '/users/' . $userId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($user['headers']['status-code'], 404);
    }

    public function testUserJWT()
    {
        // Create user
        $userId = ID::unique();
        $user = $this->client->call(Client::METHOD_POST, '/users', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'userId' => $userId,
            'email' => 'jwtuser@appwrite.io',
            'password' => 'password',
        ], false);
        $this->assertEquals($user['headers']['status-code'], 201);

        // Create JWT 0, with no session available
        $response = $this->client->call(Client::METHOD_POST, '/users/' . $userId . '/jwts', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['jwt']);
        $jwt0 = $response['body']['jwt'];

        // Ensure JWT 0 works
        $response = $this->client->call(Client::METHOD_GET, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-jwt' => $jwt0,
        ]));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals($userId, $response['body']['$id']);

        // Create two sessions
        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => 'jwtuser@appwrite.io',
            'password' => 'password',
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertEquals($userId, $response['body']['userId']);
        $this->assertNotEmpty($response['body']['$id']);
        $session1Id = $response['body']['$id'];

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => 'jwtuser@appwrite.io',
            'password' => 'password',
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertEquals($userId, $response['body']['userId']);
        $this->assertNotEmpty($response['body']['$id']);
        $session2Id = $response['body']['$id'];

        // Create JWT 1 for older session by ID
        $response = $this->client->call(Client::METHOD_POST, '/users/' . $userId . '/jwts', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'sessionId' => $session1Id
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['jwt']);
        $jwt1 = $response['body']['jwt'];

        // Ensure JWT 1 works
        $response = $this->client->call(Client::METHOD_GET, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-jwt' => $jwt1,
        ]));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals($userId, $response['body']['$id']);

        // Create JWT 2 for latest session using 'current' param
        $response = $this->client->call(Client::METHOD_POST, '/users/' . $userId . '/jwts', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'duration' => 5,
            'sessionId' => 'current'
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['jwt']);
        $jwt2 = $response['body']['jwt'];

        // Ensure JWT 2 works
        $response = $this->client->call(Client::METHOD_GET, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-jwt' => $jwt2,
        ]));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals($userId, $response['body']['$id']);

        // Wait, ensure JWT 2 no longer works because of short duration

        \sleep(10);
        $response = $this->client->call(Client::METHOD_GET, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-jwt' => $jwt2,
        ]));

        $this->assertEquals(401, $response['headers']['status-code']);

        // Delete session, ensure JWT 1 no longer works because of session missing

        $response = $this->client->call(Client::METHOD_DELETE, '/users/' . $userId . '/sessions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'sessionId' => $session1Id
        ]);

        $this->assertEquals(204, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-jwt' => $jwt1,
        ]));

        $this->assertEquals(401, $response['headers']['status-code']);

        // Ensure JWT 0 works still even with no sessions

        $response = $this->client->call(Client::METHOD_DELETE, '/users/' . $userId . '/sessions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'sessionId' => $session2Id
        ]);

        $this->assertEquals(204, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-jwt' => $jwt0,
        ]));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals($userId, $response['body']['$id']);

        // Cleanup after test

        $response = $this->client->call(Client::METHOD_DELETE, '/users/' . $userId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($response['headers']['status-code'], 204);
    }

    // TODO add test for session delete
    // TODO add test for all sessions delete
}
