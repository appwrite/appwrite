<?php

namespace Tests\E2E\Services\Account;

use Appwrite\Tests\Retry;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;
use Utopia\Database\DateTime;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;
use Utopia\Database\Validator\Datetime as DatetimeValidator;

use function sleep;

class AccountCustomClientTest extends Scope
{
    use AccountBase;
    use ProjectCustom;
    use SideClient;

    /**
     * Static cache for account data across tests
     */
    private static array $accountData = [];
    private static array $sessionData = [];
    private static array $updatedNameData = [];
    private static array $updatedPasswordData = [];
    private static array $updatedEmailData = [];
    private static array $updatedPrefsData = [];
    private static array $verificationData = [];
    private static array $verifiedData = [];
    private static array $recoveryData = [];
    private static array $phoneData = [];
    private static array $phoneSessionData = [];
    private static array $phonePasswordData = [];
    private static array $phoneUpdatedData = [];
    private static array $phoneVerificationData = [];
    private static array $magicUrlData = [];
    private static array $magicUrlSessionData = [];

    /**
     * Helper to set up an account with session
     */
    protected function setupAccountWithSession(): array
    {
        $projectId = $this->getProject()['$id'];
        $cacheKey = $projectId;

        if (!empty(self::$sessionData[$cacheKey])) {
            return self::$sessionData[$cacheKey];
        }

        // First create an account
        $accountData = $this->setupAccount();

        $email = $accountData['email'];
        $password = $accountData['password'];

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ]), [
            'email' => $email,
            'password' => $password,
        ]);

        $sessionId = $response['body']['$id'];
        $session = $response['cookies']['a_session_' . $projectId];

        self::$sessionData[$cacheKey] = array_merge($accountData, [
            'sessionId' => $sessionId,
            'session' => $session,
        ]);

        return self::$sessionData[$cacheKey];
    }

    /**
     * Helper to create a fresh account with session (bypasses cache).
     * Use this when you need a predictable session/log count for testing.
     */
    protected function createFreshAccountWithSession(): array
    {
        $projectId = $this->getProject()['$id'];

        // Use more entropy to avoid collisions in parallel test execution
        $email = uniqid('', true) . getmypid() . bin2hex(random_bytes(4)) . '@localhost.test';
        $password = 'password';
        $name = 'User Name';

        $response = $this->client->call(Client::METHOD_POST, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ]), [
            'userId' => ID::unique(),
            'email' => $email,
            'password' => $password,
            'name' => $name,
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $id = $response['body']['$id'];

        // Create session
        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ]), [
            'email' => $email,
            'password' => $password,
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $sessionId = $response['body']['$id'];
        $session = $response['cookies']['a_session_' . $projectId];

        return [
            'id' => $id,
            'email' => $email,
            'password' => $password,
            'name' => $name,
            'sessionId' => $sessionId,
            'session' => $session,
        ];
    }

    /**
     * Helper to set up a basic account
     */
    protected function setupAccount(): array
    {
        $projectId = $this->getProject()['$id'];
        $cacheKey = $projectId;

        if (!empty(self::$accountData[$cacheKey])) {
            return self::$accountData[$cacheKey];
        }

        $email = uniqid() . 'user@localhost.test';
        $password = 'password';
        $name = 'User Name';

        $response = $this->client->call(Client::METHOD_POST, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ]), [
            'userId' => ID::unique(),
            'email' => $email,
            'password' => $password,
            'name' => $name,
        ]);

        $id = $response['body']['$id'];

        self::$accountData[$cacheKey] = [
            'id' => $id,
            'email' => $email,
            'password' => $password,
            'name' => $name,
        ];

        return self::$accountData[$cacheKey];
    }

    /**
     * Helper to set up account with updated name
     */
    protected function setupAccountWithUpdatedName(): array
    {
        $projectId = $this->getProject()['$id'];
        $cacheKey = $projectId;

        if (!empty(self::$updatedNameData[$cacheKey])) {
            return self::$updatedNameData[$cacheKey];
        }

        $data = $this->setupAccountWithSession();
        $session = $data['session'];
        $newName = 'Lorem';

        $this->client->call(Client::METHOD_PATCH, '/account/name', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_' . $projectId . '=' . $session,
        ]), [
            'name' => $newName
        ]);

        self::$updatedNameData[$cacheKey] = array_merge($data, ['name' => $newName]);

        return self::$updatedNameData[$cacheKey];
    }

    /**
     * Helper to set up account with updated password
     */
    protected function setupAccountWithUpdatedPassword(): array
    {
        $projectId = $this->getProject()['$id'];
        $cacheKey = $projectId;

        if (!empty(self::$updatedPasswordData[$cacheKey])) {
            return self::$updatedPasswordData[$cacheKey];
        }

        $data = $this->setupAccountWithUpdatedName();
        $session = $data['session'];
        $password = $data['password'];

        $this->client->call(Client::METHOD_PATCH, '/account/password', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_' . $projectId . '=' . $session,
        ]), [
            'password' => 'new-password',
            'oldPassword' => $password,
        ]);

        self::$updatedPasswordData[$cacheKey] = array_merge($data, ['password' => 'new-password']);

        return self::$updatedPasswordData[$cacheKey];
    }

    /**
     * Helper to set up account with updated email
     */
    protected function setupAccountWithUpdatedEmail(): array
    {
        $projectId = $this->getProject()['$id'];
        $cacheKey = $projectId;

        if (!empty(self::$updatedEmailData[$cacheKey])) {
            return self::$updatedEmailData[$cacheKey];
        }

        $data = $this->setupAccountWithUpdatedPassword();
        $session = $data['session'];
        $newEmail = uniqid() . 'new@localhost.test';

        $this->client->call(Client::METHOD_PATCH, '/account/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_' . $projectId . '=' . $session,
        ]), [
            'email' => $newEmail,
            'password' => 'new-password',
        ]);

        self::$updatedEmailData[$cacheKey] = array_merge($data, ['email' => $newEmail]);

        return self::$updatedEmailData[$cacheKey];
    }

    /**
     * Helper to set up account with updated prefs
     */
    protected function setupAccountWithUpdatedPrefs(): array
    {
        $projectId = $this->getProject()['$id'];
        $cacheKey = $projectId;

        if (!empty(self::$updatedPrefsData[$cacheKey])) {
            return self::$updatedPrefsData[$cacheKey];
        }

        $data = $this->setupAccountWithUpdatedEmail();
        $session = $data['session'];

        $this->client->call(Client::METHOD_PATCH, '/account/prefs', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_' . $projectId . '=' . $session,
        ]), [
            'prefs' => [
                'prefKey1' => 'prefValue1',
                'prefKey2' => 'prefValue2',
            ]
        ]);

        self::$updatedPrefsData[$cacheKey] = $data;

        return self::$updatedPrefsData[$cacheKey];
    }

    /**
     * Helper to set up account with verification created
     */
    protected function setupAccountWithVerification(): array
    {
        $projectId = $this->getProject()['$id'];
        $cacheKey = $projectId;

        if (!empty(self::$verificationData[$cacheKey])) {
            return self::$verificationData[$cacheKey];
        }

        $data = $this->setupAccountWithUpdatedPrefs();
        $email = $data['email'];
        $session = $data['session'];

        $this->client->call(Client::METHOD_POST, '/account/verification', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_' . $projectId . '=' . $session,
        ]), [
            'url' => 'http://localhost/verification',
        ]);

        $lastEmail = $this->getLastEmailByAddress($email);
        $tokens = $this->extractQueryParamsFromEmailLink($lastEmail['html']);
        $verification = $tokens['secret'];

        self::$verificationData[$cacheKey] = array_merge($data, ['verification' => $verification]);

        return self::$verificationData[$cacheKey];
    }

    /**
     * Helper to set up account with verified email
     */
    protected function setupAccountWithVerifiedEmail(): array
    {
        $projectId = $this->getProject()['$id'];
        $cacheKey = $projectId;

        if (!empty(self::$verifiedData[$cacheKey])) {
            return self::$verifiedData[$cacheKey];
        }

        $data = $this->setupAccountWithVerification();
        $id = $data['id'];
        $session = $data['session'];
        $verification = $data['verification'];

        $this->client->call(Client::METHOD_PUT, '/account/verification', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_' . $projectId . '=' . $session,
        ]), [
            'userId' => $id,
            'secret' => $verification,
        ]);

        self::$verifiedData[$cacheKey] = $data;

        return self::$verifiedData[$cacheKey];
    }

    /**
     * Helper to set up account with recovery token
     */
    protected function setupAccountWithRecovery(): array
    {
        $projectId = $this->getProject()['$id'];
        $cacheKey = $projectId;

        if (!empty(self::$recoveryData[$cacheKey])) {
            return self::$recoveryData[$cacheKey];
        }

        $data = $this->setupAccountWithVerifiedEmail();
        $email = $data['email'];

        $this->client->call(Client::METHOD_POST, '/account/recovery', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ]), [
            'email' => $email,
            'url' => 'http://localhost/recovery',
        ]);

        $lastEmail = $this->getLastEmailByAddress($email);
        $tokens = $this->extractQueryParamsFromEmailLink($lastEmail['html']);
        $recovery = $tokens['secret'];

        self::$recoveryData[$cacheKey] = array_merge($data, ['recovery' => $recovery]);

        return self::$recoveryData[$cacheKey];
    }

    /**
     * Helper to set up phone account
     */
    protected function setupPhoneAccount(): array
    {
        $projectId = $this->getProject()['$id'];
        $cacheKey = $projectId;

        if (!empty(self::$phoneData[$cacheKey])) {
            return self::$phoneData[$cacheKey];
        }

        // Ensure phone auth is enabled (may have been disabled by testPhoneVerification in parallel)
        $this->ensurePhoneAuthEnabled();

        // Use a truly unique phone number for parallel test safety
        // Combine microtime, PID, and random digits to avoid collisions across parallel processes
        $number = '+1' . substr(str_replace('.', '', microtime(true)) . getmypid() . random_int(100, 999), -9);

        $response = $this->client->call(Client::METHOD_POST, '/account/tokens/phone', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ]), [
            'userId' => ID::unique(),
            'phone' => $number,
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        $userId = $response['body']['userId'];

        $smsRequest = $this->getLastRequestForProject(
            $projectId,
            Scope::REQUEST_TYPE_SMS,
            [
                'header_X-Username' => 'username',
                'header_X-Key' => 'password',
                'method' => 'POST',
            ],
            probe: function (array $request) use ($number) {
                $this->assertEquals($number, $request['data']['to'] ?? null);
            }
        );

        $this->assertNotEmpty($smsRequest, 'SMS request not found for phone number: ' . $number);

        self::$phoneData[$cacheKey] = [
            'token' => $smsRequest['data']['message'],
            'id' => $userId,
            'number' => $number,
        ];

        return self::$phoneData[$cacheKey];
    }

    /**
     * Helper to set up phone session
     */
    protected function setupPhoneSession(): array
    {
        $projectId = $this->getProject()['$id'];
        $cacheKey = $projectId;

        if (!empty(self::$phoneSessionData[$cacheKey])) {
            return self::$phoneSessionData[$cacheKey];
        }

        // Try up to 3 times with fresh phone accounts if session creation fails
        $maxRetries = 3;
        $lastError = '';

        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            // Force fresh phone account on retry
            if ($attempt > 0) {
                unset(self::$phoneData[$cacheKey]);
                \usleep(500000); // 500ms between retries
            }

            $data = $this->setupPhoneAccount();
            $id = $data['id'];
            // Extract OTP token - try the raw message first, then first word
            $rawMessage = $data['token'];
            $token = \trim($rawMessage);
            if (\str_contains($token, ' ')) {
                $token = \explode(' ', $token)[0];
            }

            $response = $this->client->call(Client::METHOD_PUT, '/account/sessions/phone', array_merge([
                'origin' => 'http://localhost',
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
            ]), [
                'userId' => $id,
                'secret' => $token,
            ]);

            if ($response['headers']['status-code'] === 201) {
                $session = $response['cookies']['a_session_' . $projectId];
                self::$phoneSessionData[$cacheKey] = array_merge($data, ['session' => $session]);
                return self::$phoneSessionData[$cacheKey];
            }

            $lastError = 'Attempt ' . ($attempt + 1) . ': Phone session creation failed (status ' . $response['headers']['status-code'] . '). Token: "' . $token . '", Raw message: "' . $rawMessage . '", UserId: ' . $id;
        }

        $this->fail($lastError);
    }

    /**
     * Helper to set up phone account converted to password
     */
    protected function setupPhoneConvertedToPassword(): array
    {
        $projectId = $this->getProject()['$id'];
        $cacheKey = $projectId;

        if (!empty(self::$phonePasswordData[$cacheKey])) {
            return self::$phonePasswordData[$cacheKey];
        }

        $data = $this->setupPhoneSession();
        $session = $data['session'];
        $email = uniqid() . 'new@localhost.test';
        $password = 'new-password';

        $response = $this->client->call(Client::METHOD_PATCH, '/account/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_' . $projectId . '=' . $session,
        ]), [
            'email' => $email,
            'password' => $password,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        // Re-login with email to get a fresh session after credential change
        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ]), [
            'email' => $email,
            'password' => $password,
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        $session = $response['cookies']['a_session_' . $projectId];

        self::$phonePasswordData[$cacheKey] = array_merge($data, [
            'email' => $email,
            'password' => $password,
            'session' => $session,
        ]);

        return self::$phonePasswordData[$cacheKey];
    }

    /**
     * Helper to set up phone account with updated phone
     */
    protected function setupPhoneUpdated(): array
    {
        $projectId = $this->getProject()['$id'];
        $cacheKey = $projectId;

        if (!empty(self::$phoneUpdatedData[$cacheKey])) {
            return self::$phoneUpdatedData[$cacheKey];
        }

        $data = $this->setupPhoneConvertedToPassword();
        $session = $data['session'];
        // Use a truly unique phone number to avoid target conflicts across parallel test runs
        $newPhone = '+456' . substr(str_replace('.', '', microtime(true)) . getmypid() . random_int(100, 999), -8);

        $response = $this->client->call(Client::METHOD_PATCH, '/account/phone', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_' . $projectId . '=' . $session,
        ]), [
            'phone' => $newPhone,
            'password' => 'new-password'
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        self::$phoneUpdatedData[$cacheKey] = array_merge($data, ['phone' => $newPhone]);

        return self::$phoneUpdatedData[$cacheKey];
    }

    /**
     * Helper to set up phone verification
     */
    protected function setupPhoneVerification(): array
    {
        $projectId = $this->getProject()['$id'];
        $cacheKey = $projectId;

        if (!empty(self::$phoneVerificationData[$cacheKey])) {
            return self::$phoneVerificationData[$cacheKey];
        }

        $data = $this->setupPhoneUpdated();
        $session = $data['session'];
        $phone = $data['phone'];

        $response = $this->client->call(Client::METHOD_POST, '/account/verification/phone', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_' . $projectId . '=' . $session,
        ]));

        $this->assertEquals(201, $response['headers']['status-code']);

        $tokenCreatedAt = $response['body']['$createdAt'];

        $smsRequest = $this->getLastRequestForProject(
            $projectId,
            Scope::REQUEST_TYPE_SMS,
            [
                'header_X-Username' => 'username',
                'header_X-Key' => 'password',
                'method' => 'POST',
            ],
            probe: function (array $request) use ($tokenCreatedAt, $phone) {
                if (!empty($phone)) {
                    $this->assertEquals($phone, $request['data']['to'] ?? null);
                }
                $tokenRecievedAt = $request['time'];
                $this->assertGreaterThan($tokenCreatedAt, $tokenRecievedAt);
            }
        );

        $this->assertNotEmpty($smsRequest, 'SMS request not found for phone verification');

        self::$phoneVerificationData[$cacheKey] = array_merge($data, [
            'token' => \substr($smsRequest['data']['message'], 0, 6)
        ]);

        return self::$phoneVerificationData[$cacheKey];
    }

    /**
     * Helper to set up magic URL account
     */
    protected function setupMagicUrl(): array
    {
        $projectId = $this->getProject()['$id'];
        $cacheKey = $projectId;

        if (!empty(self::$magicUrlData[$cacheKey])) {
            return self::$magicUrlData[$cacheKey];
        }

        $email = \time() . 'user@appwrite.io';

        $response = $this->client->call(Client::METHOD_POST, '/account/tokens/magic-url', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ]), [
            'userId' => ID::unique(),
            'email' => $email,
        ]);

        $userId = $response['body']['userId'];

        $lastEmail = $this->getLastEmailByAddress($email);
        $token = substr($lastEmail['text'], strpos($lastEmail['text'], '&secret=', 0) + 8, 64);

        self::$magicUrlData[$cacheKey] = [
            'token' => $token,
            'id' => $userId,
            'email' => $email,
        ];

        return self::$magicUrlData[$cacheKey];
    }

    /**
     * Helper to set up magic URL session
     */
    protected function setupMagicUrlSession(): array
    {
        $projectId = $this->getProject()['$id'];
        $cacheKey = $projectId;

        if (!empty(self::$magicUrlSessionData[$cacheKey])) {
            return self::$magicUrlSessionData[$cacheKey];
        }

        $data = $this->setupMagicUrl();
        $id = $data['id'];
        $token = $data['token'];

        $response = $this->client->call(Client::METHOD_PUT, '/account/sessions/magic-url', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ]), [
            'userId' => $id,
            'secret' => $token,
        ]);

        $sessionId = $response['body']['$id'];
        $session = $response['cookies']['a_session_' . $projectId];

        self::$magicUrlSessionData[$cacheKey] = array_merge($data, [
            'sessionId' => $sessionId,
            'session' => $session,
        ]);

        return self::$magicUrlSessionData[$cacheKey];
    }

    /**
     * Helper to create an anonymous session (returns new session each time)
     */
    protected function createAnonymousSession(): string
    {
        $projectId = $this->getProject()['$id'];

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/anonymous', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ]);

        return $response['cookies']['a_session_' . $projectId];
    }

    /**
     * Helper to delete any existing user with the given email.
     * Used to prevent parallel test conflicts when tests share
     * hardcoded emails (e.g. from mock OAuth providers).
     */
    protected function deleteUserByEmail(string $email): void
    {
        $projectId = $this->getProject()['$id'];
        $apiKey = $this->getProject()['apiKey'];

        $response = $this->client->call(Client::METHOD_GET, '/users', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apiKey,
        ], [
            'queries' => [
                Query::equal('email', [$email])->toString(),
            ],
        ]);

        if ($response['headers']['status-code'] === 200) {
            foreach ($response['body']['users'] ?? [] as $user) {
                $this->client->call(Client::METHOD_DELETE, '/users/' . $user['$id'], [
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $projectId,
                    'x-appwrite-key' => $apiKey,
                ]);
            }
        }
    }

    /**
     * Helper to ensure phone auth is enabled for the project.
     * Needed because testPhoneVerification disables it and other
     * parallel tests may need it.
     */
    protected function ensurePhoneAuthEnabled(): void
    {
        $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $this->getProject()['$id'] . '/auth/phone', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
        ]), [
            'status' => true,
        ]);
    }

    public function testCreateAccountSession(): void
    {
        $data = $this->setupAccount();
        $email = $data['email'];
        $password = $data['password'];

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'password' => $password,
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotFalse(\DateTime::createFromFormat('Y-m-d\TH:i:s.uP', $response['body']['expire']));

        $sessionId = $response['body']['$id'];
        $session = $response['cookies']['a_session_' . $this->getProject()['$id']];

        // apiKey is only available in custom client test
        $apiKey = $this->getProject()['apiKey'];
        if (!empty($apiKey)) {
            $userId = $response['body']['userId'];
            $response = $this->client->call(Client::METHOD_GET, '/users/' . $userId, array_merge([
                'origin' => 'http://localhost',
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $apiKey,
            ]));
            $this->assertEquals(200, $response['headers']['status-code']);
            $this->assertArrayHasKey('accessedAt', $response['body']);
            $this->assertNotEmpty($response['body']['accessedAt']);
        }

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'password' => $password,
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertEmpty($response['body']['secret']);
        $this->assertNotFalse(\DateTime::createFromFormat('Y-m-d\TH:i:s.uP', $response['body']['expire']));

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'password' => $password,
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email . 'x',
            'password' => $password,
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'password' => $password . 'x',
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => '',
            'password' => '',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);
    }

    public function testGetAccount(): void
    {
        $data = $this->setupAccountWithSession();
        $email = $data['email'];
        $name = $data['name'];
        $session = $data['session'];

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertTrue((new DatetimeValidator())->isValid($response['body']['registration']));
        $this->assertEquals($response['body']['email'], $email);
        $this->assertEquals($response['body']['name'], $name);
        $this->assertArrayHasKey('accessedAt', $response['body']);
        $this->assertNotEmpty($response['body']['accessedAt']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_GET, '/account', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]));

        $this->assertEquals(401, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/account', [
            'content-type' => 'application/json',
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session . 'xx',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);
    }

    public function testGetAccountPrefs(): void
    {
        $data = $this->setupAccountWithSession();
        $session = $data['session'];

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/account/prefs', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertIsArray($response['body']);
        $this->assertEmpty($response['body']);
        $this->assertCount(0, $response['body']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_GET, '/account/prefs', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]));

        $this->assertEquals(401, $response['headers']['status-code']);
    }

    public function testGetAccountSessions(): void
    {
        // Use fresh account for predictable session count
        $data = $this->createFreshAccountWithSession();
        $session = $data['session'];
        $sessionId = $data['sessionId'];

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/account/sessions', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(1, $response['body']['total']);
        $this->assertEquals($sessionId, $response['body']['sessions'][0]['$id']);
        $this->assertEmpty($response['body']['sessions'][0]['secret']);

        $this->assertEquals('Windows', $response['body']['sessions'][0]['osName']);
        $this->assertEquals('WIN', $response['body']['sessions'][0]['osCode']);
        $this->assertEquals('10', $response['body']['sessions'][0]['osVersion']);

        $this->assertEquals('browser', $response['body']['sessions'][0]['clientType']);
        $this->assertEquals('Chrome', $response['body']['sessions'][0]['clientName']);
        $this->assertEquals('CH', $response['body']['sessions'][0]['clientCode']);
        $this->assertEquals('70.0', $response['body']['sessions'][0]['clientVersion']);
        $this->assertEquals('Blink', $response['body']['sessions'][0]['clientEngine']);
        $this->assertEquals('desktop', $response['body']['sessions'][0]['deviceName']);
        $this->assertEquals('', $response['body']['sessions'][0]['deviceBrand']);
        $this->assertEquals('', $response['body']['sessions'][0]['deviceModel']);
        $this->assertEquals($response['body']['sessions'][0]['ip'], filter_var($response['body']['sessions'][0]['ip'], FILTER_VALIDATE_IP));

        $this->assertEquals('--', $response['body']['sessions'][0]['countryCode']);
        $this->assertEquals('Unknown', $response['body']['sessions'][0]['countryName']);

        $this->assertEquals(true, $response['body']['sessions'][0]['current']);

        $this->assertNotFalse(\DateTime::createFromFormat('Y-m-d\TH:i:s.uP', $response['body']['sessions'][0]['expire']));
        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_GET, '/account/sessions', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]));

        $this->assertEquals(401, $response['headers']['status-code']);
    }

    public function testGetAccountLogs(): void
    {
        sleep(5);
        // Use fresh account for predictable log count
        $data = $this->createFreshAccountWithSession();
        $session = $data['session'];

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/account/logs', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertIsArray($response['body']['logs']);
        $this->assertNotEmpty($response['body']['logs']);
        // Fresh account: session.create is always logged. user.create audit may or may not
        // be present depending on async audit processing timing.
        $logCount = count($response['body']['logs']);
        $this->assertContains($logCount, [1, 2]);
        $this->assertIsNumeric($response['body']['total']);

        // Check session.create log (logs[0] - most recent)
        $this->assertEquals('Windows', $response['body']['logs'][0]['osName']);
        $this->assertEquals('WIN', $response['body']['logs'][0]['osCode']);
        $this->assertEquals('10', $response['body']['logs'][0]['osVersion']);

        $this->assertEquals('browser', $response['body']['logs'][0]['clientType']);
        $this->assertEquals('Chrome', $response['body']['logs'][0]['clientName']);
        $this->assertEquals('CH', $response['body']['logs'][0]['clientCode']);
        $this->assertEquals('70.0', $response['body']['logs'][0]['clientVersion']);
        $this->assertEquals('Blink', $response['body']['logs'][0]['clientEngine']);

        $this->assertEquals('desktop', $response['body']['logs'][0]['deviceName']);
        $this->assertEquals('', $response['body']['logs'][0]['deviceBrand']);
        $this->assertEquals('', $response['body']['logs'][0]['deviceModel']);
        $this->assertEquals(filter_var($response['body']['logs'][0]['ip'], FILTER_VALIDATE_IP), $response['body']['logs'][0]['ip']);

        $this->assertEquals('--', $response['body']['logs'][0]['countryCode']);
        $this->assertEquals('Unknown', $response['body']['logs'][0]['countryName']);

        if ($logCount === 2) {
            // Check user.create log (logs[1] - oldest)
            $this->assertEquals('user.create', $response['body']['logs'][1]['event']);
            $this->assertEquals(filter_var($response['body']['logs'][1]['ip'], FILTER_VALIDATE_IP), $response['body']['logs'][1]['ip']);
            $this->assertTrue((new DatetimeValidator())->isValid($response['body']['logs'][1]['time']));
        }

        $responseLimit = $this->client->call(Client::METHOD_GET, '/account/logs', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'queries' => [
                Query::limit(1)->toString()
            ]
        ]);

        $this->assertEquals(200, $responseLimit['headers']['status-code']);
        $this->assertIsArray($responseLimit['body']['logs']);
        $this->assertNotEmpty($responseLimit['body']['logs']);
        $this->assertCount(1, $responseLimit['body']['logs']);
        $this->assertIsNumeric($responseLimit['body']['total']);

        $this->assertEquals($response['body']['logs'][0], $responseLimit['body']['logs'][0]);

        $responseOffset = $this->client->call(Client::METHOD_GET, '/account/logs', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'queries' => [
                Query::offset(1)->toString()
            ]
        ]);

        $this->assertEquals($responseOffset['headers']['status-code'], 200);
        $this->assertIsArray($responseOffset['body']['logs']);
        // With offset(1), remaining logs = logCount - 1
        $this->assertCount($logCount - 1, $responseOffset['body']['logs']);
        $this->assertIsNumeric($responseOffset['body']['total']);

        if ($logCount === 2) {
            $this->assertEquals($response['body']['logs'][1], $responseOffset['body']['logs'][0]);
        }

        $responseLimitOffset = $this->client->call(Client::METHOD_GET, '/account/logs', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'queries' => [
                Query::offset(1)->toString(),
                Query::limit(1)->toString()
            ]
        ]);

        $this->assertEquals(200, $responseLimitOffset['headers']['status-code']);
        $this->assertIsArray($responseLimitOffset['body']['logs']);
        // With offset(1)+limit(1), remaining logs = min(1, logCount - 1)
        $this->assertCount(min(1, $logCount - 1), $responseLimitOffset['body']['logs']);
        $this->assertIsNumeric($responseLimitOffset['body']['total']);

        if ($logCount === 2) {
            $this->assertEquals($response['body']['logs'][1], $responseLimitOffset['body']['logs'][0]);
        }

        /**
         * Test for total=false
         */
        $logsWithIncludeTotalFalse = $this->client->call(Client::METHOD_GET, '/account/logs', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'total' => false
        ]);

        $this->assertEquals(200, $logsWithIncludeTotalFalse['headers']['status-code']);
        $this->assertIsArray($logsWithIncludeTotalFalse['body']);
        $this->assertIsArray($logsWithIncludeTotalFalse['body']['logs']);
        $this->assertIsInt($logsWithIncludeTotalFalse['body']['total']);
        $this->assertEquals(0, $logsWithIncludeTotalFalse['body']['total']);
        $this->assertGreaterThan(0, count($logsWithIncludeTotalFalse['body']['logs']));

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_GET, '/account/logs', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]));

        $this->assertEquals(401, $response['headers']['status-code']);
    }

    // TODO Add tests for OAuth2 session creation

    public function testUpdateAccountName(): void
    {
        $data = $this->setupAccountWithSession();
        $email = $data['email'];
        $session = $data['session'];
        $newName = 'Lorem';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/account/name', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'name' => $newName
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertIsArray($response['body']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertTrue((new DatetimeValidator())->isValid($response['body']['registration']));
        $this->assertEquals($response['body']['email'], $email);
        $this->assertEquals($response['body']['name'], $newName);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/account/name', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]));

        $this->assertEquals(401, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_PATCH, '/account/name', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), []);

        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_PATCH, '/account/name', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'name' => 'ocSRq1d3QphHivJyUmYY7WMnrxyjdk5YvVwcDqx2zS0coxESN8RmsQwLWw5Whnf0WbVohuFWTRAaoKgCOO0Y0M7LwgFnZmi8881Y72222222222222222222222222222'
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);
    }

    #[Retry(count: 1)]
    public function testUpdateAccountPassword(): void
    {
        $data = $this->setupAccountWithUpdatedName();
        $email = $data['email'];
        $password = $data['password'];
        $session = $data['session'];

        for ($i = 0; $i < 5; $i++) {
            $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
                'origin' => 'http://localhost',
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ]), [
                'email' => $email,
                'password' => $password,
            ]);

            $this->assertEquals(201, $response['headers']['status-code']);
            sleep(1);
        }

        $response = $this->client->call(Client::METHOD_GET, '/account/sessions', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $allSessions = array_map(fn ($sessionDetails) => $sessionDetails['$id'], $response['body']['sessions']);

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/account/password', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'password' => 'new-password',
            'oldPassword' => $password,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertIsArray($response['body']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertTrue((new DatetimeValidator())->isValid($response['body']['registration']));
        $this->assertEquals($response['body']['email'], $email);

        $currentSessionId = $data['sessionId'] ?? '';
        $response = $this->client->call(Client::METHOD_GET, '/account/sessions', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(1, $response['body']['total']);
        // checking the current session or not
        $this->assertEquals($currentSessionId, $response['body']['sessions'][0]['$id']);
        $this->assertTrue($response['body']['sessions'][0]['current']);

        // checking for all non active sessions are cleared
        foreach ($allSessions as $sessionId) {
            if ($currentSessionId === $sessionId) {
                $response = $this->client->call(Client::METHOD_GET, '/account/sessions/current', array_merge([
                    'origin' => 'http://localhost',
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id'],
                    'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
                ]));

                $this->assertEquals(200, $response['headers']['status-code']);
            } else {
                $response = $this->client->call(Client::METHOD_GET, '/account/sessions/'.$sessionId, array_merge([
                    'origin' => 'http://localhost',
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id'],
                    'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
                ]));

                $this->assertEquals(404, $response['headers']['status-code']);
            }
        }

        $newPassword = 'new-password';
        // updating the invalidateSession to false to check sessions are not invalidated
        $this->updateProjectinvalidateSessionsProperty(false);
        for ($i = 0; $i < 5; $i++) {
            $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
                'origin' => 'http://localhost',
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ]), [
                'email' => $email,
                'password' => $newPassword,
            ]);

            $this->assertEquals(201, $response['headers']['status-code']);
            sleep(1);
        }

        $response = $this->client->call(Client::METHOD_GET, '/account/sessions', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $allSessions = array_map(fn ($sessionDetails) => $sessionDetails['$id'], $response['body']['sessions']);

        $response = $this->client->call(Client::METHOD_PATCH, '/account/password', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'password' => $newPassword,
            'oldPassword' => $newPassword,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        foreach ($allSessions as $sessionId) {
            $response = $this->client->call(Client::METHOD_GET, '/account/sessions/'.$sessionId, headers: array_merge([
                'origin' => 'http://localhost',
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
            ]));

            $this->assertEquals(200, $response['headers']['status-code']);
        }

        // setting invalidateSession to true to check the sessions are cleared or not
        $this->updateProjectinvalidateSessionsProperty(true);
        $response = $this->client->call(Client::METHOD_PATCH, '/account/password', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'password' => $newPassword,
            'oldPassword' => $newPassword,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/account/sessions', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $allSessions = array_map(fn ($sessionDetails) => $sessionDetails['$id'], $response['body']['sessions']);

        foreach ($allSessions as $sessionId) {
            if ($currentSessionId !== $sessionId) {
                $response = $this->client->call(Client::METHOD_GET, '/account/sessions/'.$sessionId, array_merge([
                    'origin' => 'http://localhost',
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id'],
                    'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
                ]));

                $this->assertEquals(404, $response['headers']['status-code']);
            }
        }

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'password' => $newPassword,
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);


        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/account/password', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]));

        $this->assertEquals(401, $response['headers']['status-code']);
        $response = $this->client->call(Client::METHOD_PATCH, '/account/password', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), []);

        $this->assertEquals(400, $response['headers']['status-code']);

        /**
         * Existing user tries to update password by passing wrong old password -> SHOULD FAIL
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/account/password', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'password' => 'new-password',
            'oldPassword' => $password,
        ]);
        $this->assertEquals(401, $response['headers']['status-code']);

        /**
         * Existing user tries to update password without passing old password -> SHOULD FAIL
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/account/password', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'password' => 'new-password'
        ]);
        $this->assertEquals(401, $response['headers']['status-code']);
    }

    public function testUpdateAccountEmail(): void
    {
        $data = $this->setupAccountWithUpdatedPassword();
        $newEmail = uniqid() . 'new@localhost.test';
        $session = $data['session'];

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/account/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'email' => $newEmail,
            'password' => 'new-password',
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertIsArray($response['body']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertTrue((new DatetimeValidator())->isValid($response['body']['registration']));
        $this->assertEquals($response['body']['email'], $newEmail);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/account/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]));

        $this->assertEquals(401, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_PATCH, '/account/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), []);

        $this->assertEquals(400, $response['headers']['status-code']);

        // Test if we can create a new account with the old email

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_POST, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-dev-key' => $this->getProject()['devKey'] ?? ''
        ]), [
            'userId' => ID::unique(),
            'email' => $data['email'],
            'password' => $data['password'],
            'name' => $data['name'],
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertTrue((new DatetimeValidator())->isValid($response['body']['registration']));
        $this->assertEquals($response['body']['email'], $data['email']);
        $this->assertEquals($response['body']['name'], $data['name']);
    }

    public function testUpdateAccountPrefs(): void
    {
        $data = $this->setupAccountWithUpdatedEmail();
        $session = $data['session'];

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/account/prefs', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'prefs' => [
                'prefKey1' => 'prefValue1',
                'prefKey2' => 'prefValue2',
            ]
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertIsArray($response['body']);
        $this->assertNotEmpty($response['body']);
        $this->assertEquals('prefValue1', $response['body']['prefs']['prefKey1']);
        $this->assertEquals('prefValue2', $response['body']['prefs']['prefKey2']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/account/prefs', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]));

        $this->assertEquals(401, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_PATCH, '/account/prefs', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'prefs' => '{}'
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);


        $response = $this->client->call(Client::METHOD_PATCH, '/account/prefs', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'prefs' => '[]'
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_PATCH, '/account/prefs', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'prefs' => '{"test": "value"}'
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        /**
         * Prefs size exceeded
         */
        $prefsObject = ["longValue" => str_repeat("🍰", 100000)];

        $response = $this->client->call(Client::METHOD_PATCH, '/account/prefs', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'prefs' => $prefsObject
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        // Now let's test the same thing, but with normal symbol instead of multi-byte cake emoji
        $prefsObject = ["longValue" => str_repeat("-", 100000)];

        $response = $this->client->call(Client::METHOD_PATCH, '/account/prefs', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'prefs' => $prefsObject
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);
    }

    public function testCreateAccountVerification(): void
    {
        $data = $this->setupAccountWithUpdatedPrefs();
        $email = $data['email'];
        $name = $data['name'];
        $session = $data['session'];

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_POST, '/account/verification', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,

        ]), [
            'url' => 'http://localhost/verification',
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEmpty($response['body']['secret']);
        $this->assertTrue((new DatetimeValidator())->isValid($response['body']['expire']));

        $lastEmail = $this->getLastEmailByAddress($email);

        $this->assertNotEmpty($lastEmail, 'Email not found for address: ' . $email);
        $this->assertEquals($name, $lastEmail['to'][0]['name']);
        $this->assertEquals('Account Verification for ' . $this->getProject()['name'], $lastEmail['subject']);
        $this->assertStringContainsStringIgnoringCase('Verify your email to activate your ' . $this->getProject()['name'] . ' account.', $lastEmail['text']);

        $tokens = $this->extractQueryParamsFromEmailLink($lastEmail['html']);
        $verification = $tokens['secret'];
        $expectedExpire = DateTime::formatTz($response['body']['expire']);
        $this->assertEquals($expectedExpire, $tokens['expire']);

        // Secret check
        $this->assertArrayHasKey('secret', $tokens);
        $this->assertNotEmpty($tokens['secret']);

        // User ID check
        $this->assertArrayHasKey('userId', $tokens);
        $this->assertNotEmpty($tokens['userId']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_POST, '/account/verification', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'url' => 'localhost/verification',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/account/verification', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'url' => 'http://remotehost/verification',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);
    }

    public function testUpdateAccountVerification(): void
    {
        $data = $this->setupAccountWithVerification();
        $id = $data['id'];
        $session = $data['session'];
        $verification = $data['verification'];

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_PUT, '/account/verification', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'userId' => $id,
            'secret' => $verification,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_PUT, '/account/verification', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'userId' => ID::custom('ewewe'),
            'secret' => $verification,
        ]);

        $this->assertEquals(404, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_PUT, '/account/verification', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'userId' => $id,
            'secret' => 'sdasdasdasd',
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);
    }

    public function testDeleteAccountSession(): void
    {
        $data = $this->setupAccountWithVerifiedEmail();
        $email = $data['email'];
        $password = $data['password'];
        $session = $data['session'];

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'password' => $password,
        ]);

        $sessionNewId = $response['body']['$id'];
        $sessionNew = $response['cookies']['a_session_' . $this->getProject()['$id']];

        $this->assertEquals(201, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/account', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $sessionNew,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_DELETE, '/account/sessions/' . $sessionNewId, array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $sessionNew,
        ]));

        $this->assertEquals(204, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $this->assertEquals(200, $response['headers']['status-code']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_GET, '/account', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $sessionNew,
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);
    }

    public function testDeleteAccountSessionCurrent(): void
    {
        $data = $this->setupAccountWithVerifiedEmail();
        $email = $data['email'];
        $password = $data['password'];

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'password' => $password,
        ]);

        $sessionNew = $response['cookies']['a_session_' . $this->getProject()['$id']];

        $this->assertEquals(201, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/account', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $sessionNew,
            'x-appwrite-project' => $this->getProject()['$id'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_DELETE, '/account/sessions/current', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $sessionNew,
            'x-appwrite-project' => $this->getProject()['$id'],
        ]);

        $this->assertEquals(204, $response['headers']['status-code']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_GET, '/account', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $sessionNew,
            'x-appwrite-project' => $this->getProject()['$id'],
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);
    }

    public function testDeleteAccountSessions(): void
    {
        $data = $this->setupAccountWithVerifiedEmail();
        $session = $data['session'];

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_DELETE, '/account/sessions', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $this->assertEquals(204, $response['headers']['status-code']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_GET, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]));

        $this->assertEquals(401, $response['headers']['status-code']);

    }

    public function testCreateAccountRecovery(): void
    {
        $data = $this->setupAccountWithVerifiedEmail();
        $email = $data['email'];
        $name = $data['name'];

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_POST, '/account/recovery', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'url' => 'http://localhost/recovery',
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEmpty($response['body']['secret']);
        $this->assertTrue((new DatetimeValidator())->isValid($response['body']['expire']));

        $lastEmail = $this->getLastEmailByAddress($email);

        $this->assertNotEmpty($lastEmail, 'Email not found for address: ' . $email);
        $this->assertEquals($name, $lastEmail['to'][0]['name']);
        $this->assertEquals('Password Reset for ' . $this->getProject()['name'], $lastEmail['subject']);
        $this->assertStringContainsStringIgnoringCase('Reset your ' . $this->getProject()['name'] . ' password using the link.', $lastEmail['text']);


        $tokens = $this->extractQueryParamsFromEmailLink($lastEmail['html']);

        // Secret check
        $this->assertArrayHasKey('secret', $tokens);
        $this->assertNotEmpty($tokens['secret']);
        $this->assertNotFalse($response['body']['secret']);

        // User ID check
        $this->assertArrayHasKey('userId', $tokens);
        $this->assertNotEmpty($tokens['userId']);
        $this->assertNotFalse($response['body']['userId']);

        // Expire check
        $this->assertArrayHasKey('expire', $tokens);
        $this->assertNotEmpty($tokens['expire']);
        $this->assertEquals(
            DateTime::formatTz($response['body']['expire']),
            $tokens['expire']
        );

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_POST, '/account/recovery', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'url' => 'localhost/recovery',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/account/recovery', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'url' => 'http://remotehost/recovery',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/account/recovery', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => 'not-found@localhost.test',
            'url' => 'http://localhost/recovery',
        ]);

        $this->assertEquals(404, $response['headers']['status-code']);
    }

    #[Retry(count: 1)]
    public function testUpdateAccountRecovery(): void
    {
        $data = $this->setupAccountWithRecovery();
        $id = $data['id'];
        $recovery = $data['recovery'];
        $newPassword = 'test-recovery';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_PUT, '/account/recovery', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'userId' => $id,
            'secret' => $recovery,
            'password' => $newPassword,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_PUT, '/account/recovery', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'userId' => ID::custom('ewewe'),
            'secret' => $recovery,
            'password' => $newPassword,
        ]);

        $this->assertEquals(404, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_PUT, '/account/recovery', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'userId' => $id,
            'secret' => 'sdasdasdasd',
            'password' => $newPassword,
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);
    }

    public function testSessionAlert(): void
    {
        $email = uniqid() . 'session-alert@appwrite.io';
        $password = 'password123';
        $name = 'Session Alert Tester';

        // Enable session alerts
        $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $this->getProject()['$id'] . '/auth/session-alerts', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
        ]), [
            'alerts' => true,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        // Create a new account
        $response = $this->client->call(Client::METHOD_POST, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-dev-key' => $this->getProject()['devKey'] ?? ''
        ]), [
            'userId' => ID::unique(),
            'email' => $email,
            'password' => $password,
            'name' => $name,
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        // Create first session for the new account
        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'user-agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
        ]), [
            'email' => $email,
            'password' => $password,
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        // Create second session for the new account
        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'user-agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
        ]), [
            'email' => $email,
            'password' => $password,
        ]);


        // Check the alert email
        $lastEmail = $this->getLastEmailByAddress($email);

        $this->assertNotEmpty($lastEmail, 'Email not found for address: ' . $email);
        $this->assertStringContainsString('Security alert: new session', $lastEmail['subject']);
        $this->assertStringContainsString($response['body']['ip'], $lastEmail['text']); // IP Address
        $this->assertStringContainsString('Unknown', $lastEmail['text']); // Country
        $this->assertStringContainsString($response['body']['clientName'], $lastEmail['text']); // Client name
        $this->assertStringNotContainsStringIgnoringCase('Appwrite logo', $lastEmail['html']);

        // Verify no alert sent in OTP login
        $response = $this->client->call(Client::METHOD_POST, '/account/tokens/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'userId' => ID::unique(),
            'email' => 'otpuser3@appwrite.io'
        ]);

        $this->assertEquals($response['headers']['status-code'], 201);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertNotEmpty($response['body']['$createdAt']);
        $this->assertNotEmpty($response['body']['userId']);
        $this->assertNotEmpty($response['body']['expire']);
        $this->assertEmpty($response['body']['secret']);
        $this->assertEmpty($response['body']['phrase']);
        $this->assertStringContainsStringIgnoringCase('New login detected on '. $this->getProject()['name'], $lastEmail['text']);

        $userId = $response['body']['userId'];

        $lastEmail = $this->getLastEmailByAddress('otpuser3@appwrite.io');

        $this->assertNotEmpty($lastEmail, 'Email not found for address: otpuser3@appwrite.io');
        $this->assertEquals('OTP for ' . $this->getProject()['name'] . ' Login', $lastEmail['subject']);

        // Find 6 concurrent digits in email text - OTP
        preg_match_all("/\b\d{6}\b/", $lastEmail['text'], $matches);
        $code = ($matches[0] ?? [])[0] ?? '';

        $this->assertNotEmpty($code);

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/token', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'userId' => $userId,
            'secret' => $code
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertEquals($userId, $response['body']['userId']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertNotEmpty($response['body']['expire']);
        $this->assertEmpty($response['body']['secret']);

        $lastEmailId = $lastEmail['id'];
        $lastEmail = $this->getLastEmailByAddress('otpuser3@appwrite.io');
        $this->assertEquals($lastEmailId, $lastEmail['id']);
    }

    public function testCreateOAuth2AccountSession(): void
    {
        // Just ensure we have a session set up
        $this->setupAccountWithSession();

        $provider = 'mock';
        $appId = '1';
        $secret = '123456';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $this->getProject()['$id'] . '/oauth2', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
        ]), [
            'provider' => $provider,
            'appId' => $appId,
            'secret' => $secret,
            'enabled' => true,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/account/sessions/oauth2/' . $provider, array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'success' => 'http://localhost/v1/mock/tests/general/oauth2/success',
            'failure' => 'http://localhost/v1/mock/tests/general/oauth2/failure',
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('success', $response['body']['result']);

        /**
         * Test for Failure when disabled
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $this->getProject()['$id'] . '/oauth2', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
        ]), [
            'provider' => $provider,
            'appId' => $appId,
            'secret' => $secret,
            'enabled' => false,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/account/sessions/oauth2/' . $provider, array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'success' => 'http://localhost/v1/mock/tests/general/oauth2/success',
            'failure' => 'http://localhost/v1/mock/tests/general/oauth2/failure',
        ]);

        $this->assertEquals(412, $response['headers']['status-code']);
    }

    public function testCreateOidcOAuth2Token(): void
    {
        $provider = 'oidc';
        $appId = '1';

        // Valid well-known configuration
        $secret = '{
            "wellKnownEndpoint": "https://accounts.google.com/.well-known/openid-configuration",
            "authorizationEndpoint": "https://accounts.google.com/o/oauth2/v2/auth",
            "tokenEndpoint": "https://oauth2.googleapis.com/token",
            "userinfoEndpoint": "https://openidconnect.googleapis.com/v1/userinfo"
        }';

        $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $this->getProject()['$id'] . '/oauth2', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
        ]), [
            'provider' => $provider,
            'appId' => $appId,
            'secret' => $secret,
            'enabled' => true,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/account/tokens/oauth2/' . $provider, array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'provider' => $provider,
            'success' => 'http://localhost/v1/mock/tests/general/oauth2/success',
            'failure' => 'http://localhost/v1/mock/tests/general/oauth2/failure',
        ], true, false);

        $this->assertEquals(301, $response['headers']['status-code']);

        // Invalid well-known configuration
        $secret = '{}';

        $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $this->getProject()['$id'] . '/oauth2', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
        ]), [
            'provider' => $provider,
            'appId' => $appId,
            'secret' => $secret,
            'enabled' => true,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/account/tokens/oauth2/' . $provider, array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'provider' => $provider,
            'success' => 'http://localhost/v1/mock/tests/general/oauth2/success',
            'failure' => 'http://localhost/v1/mock/tests/general/oauth2/failure',
        ]);

        $this->assertEquals(500, $response['headers']['status-code']);

        // Clean up - disable the OIDC provider to avoid polluting other parallel tests
        $this->client->call(Client::METHOD_PATCH, '/projects/' . $this->getProject()['$id'] . '/oauth2', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
        ]), [
            'provider' => $provider,
            'enabled' => false,
        ]);
    }

    public function testBlockedAccount(): void
    {
        $email = uniqid() . 'user@localhost.test';
        $password = 'password';
        $name = 'User Name (blocked)';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_POST, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'userId' => ID::unique(),
            'email' => $email,
            'password' => $password,
            'name' => $name,
        ]);

        $id = $response['body']['$id'];

        $this->assertEquals(201, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'password' => $password,
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        $sessionId = $response['body']['$id'];
        $session = $response['cookies']['a_session_' . $this->getProject()['$id']];

        $response = $this->client->call(Client::METHOD_GET, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $this->assertEquals(200, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_PATCH, '/users/' . $id . '/status', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'status' => false,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $this->assertEquals(401, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'password' => $password,
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);
    }


    public function testSelfBlockedAccount(): void
    {
        $email = uniqid() . 'user55@localhost.test';
        $password = 'password';
        $name = 'User Name (self blocked)';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_POST, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'userId' => ID::unique(),
            'email' => $email,
            'password' => $password,
            'name' => $name,
        ]);

        $id = $response['body']['$id'];

        $this->assertEquals(201, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'password' => $password,
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        $session = $response['cookies']['a_session_' . $this->getProject()['$id']];

        $response = $this->client->call(Client::METHOD_GET, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $this->assertEquals(200, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_PATCH, '/account/status', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ], [
            'status' => false,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertStringContainsString('a_session_' . $this->getProject()['$id'] . '=deleted', $response['headers']['set-cookie']);
        $this->assertEquals('[]', $response['headers']['x-fallback-cookies']);

        $response = $this->client->call(Client::METHOD_GET, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $this->assertEquals(401, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'password' => $password,
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);
    }

    public function testCreateJWT(): void
    {
        $email = uniqid() . 'user@localhost.test';
        $password = 'password';
        $name = 'User Name (JWT)';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_POST, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'userId' => ID::unique(),
            'email' => $email,
            'password' => $password,
            'name' => $name,
        ]);

        $id = $response['body']['$id'];

        $this->assertEquals(201, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'password' => $password,
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        $sessionId = $response['body']['$id'];
        $session = $response['cookies']['a_session_' . $this->getProject()['$id']];

        $response = $this->client->call(Client::METHOD_GET, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $this->assertEquals(200, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/account/jwt', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertEquals(119, $response['headers']['x-ratelimit-remaining']);
        $this->assertNotEmpty($response['body']['jwt']);
        $this->assertIsString($response['body']['jwt']);

        $jwt = $response['body']['jwt'];

        $response = $this->client->call(Client::METHOD_GET, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-jwt' => 'wrong-token',
        ]));

        $this->assertEquals(401, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-jwt' => $jwt,
        ]));

        $this->assertEquals(200, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_DELETE, '/account/sessions/' . $sessionId, array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $this->assertEquals(204, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-jwt' => $jwt,
        ]));

        $this->assertEquals(401, $response['headers']['status-code']);

        // Test JWT with custom duration
        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'password' => $password,
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        $session = $response['cookies']['a_session_' . $this->getProject()['$id']];

        $response = $this->client->call(Client::METHOD_POST, '/account/jwt', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'duration' => 5
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['jwt']);

        $jwt = $response['body']['jwt'];

        // Ensure JWT works before expiration
        $response = $this->client->call(Client::METHOD_GET, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-jwt' => $jwt,
        ]));

        $this->assertEquals(200, $response['headers']['status-code']);

        // Wait for JWT to expire
        \sleep(6);

        // Ensure JWT no longer works after expiration
        $response = $this->client->call(Client::METHOD_GET, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-jwt' => $jwt,
        ]));

        $this->assertEquals(401, $response['headers']['status-code']);
    }

    public function testCreateAnonymousAccount(): void
    {
        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/anonymous', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertIsArray($response['body']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEmpty($response['body']['secret']);

        $session = $response['cookies']['a_session_' . $this->getProject()['$id']];

        \usleep(1000 * 30); // wait for 30ms to let the shutdown update accessedAt

        $apiKey = $this->getProject()['apiKey'];
        $userId = $response['body']['userId'];
        $response = $this->client->call(Client::METHOD_GET, '/users/' . $userId, array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $apiKey,
        ]));
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertArrayHasKey('accessedAt', $response['body']);

        $this->assertNotEmpty($response['body']['accessedAt']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/anonymous', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);
    }

    public function testCreateAnonymousAccountVerification(): void
    {
        $session = $this->createAnonymousSession();
        $response = $this->client->call(Client::METHOD_POST, '/account/verification', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'url' => 'http://localhost/verification',
        ]);

        $this->assertEquals(400, $response['body']['code']);
        $this->assertEquals('user_email_not_found', $response['body']['type']);
    }

    public function testUpdateAnonymousAccountPassword(): void
    {
        $session = $this->createAnonymousSession();
        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/account/password', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'oldPassword' => '',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);
    }

    public function testUpdateAnonymousAccountEmail(): void
    {
        $session = $this->createAnonymousSession();
        $email = uniqid() . 'new@localhost.test';

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/account/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'email' => $email,
            'password' => '',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);
    }

    public function testConvertAnonymousAccount(): void
    {
        $session = $this->createAnonymousSession();
        $email = uniqid() . 'new@localhost.test';
        $password = 'new-password';

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_POST, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'userId' => ID::unique(),
            'email' => $email,
            'password' => $password
        ]);

        $response = $this->client->call(Client::METHOD_PATCH, '/account/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'email' => $email,
            'password' => $password,
        ]);

        $this->assertEquals(409, $response['headers']['status-code']);

        /**
         * Test for SUCCESS
         */
        $email = uniqid() . 'new@localhost.test';

        $response = $this->client->call(Client::METHOD_PATCH, '/account/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'email' => $email,
            'password' => $password,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertIsArray($response['body']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertTrue((new DatetimeValidator())->isValid($response['body']['registration']));
        $this->assertEquals($response['body']['email'], $email);

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'password' => $password,
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/account/verification', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'url' => 'http://localhost'
        ]);


        $this->assertEquals(201, $response['headers']['status-code']);
    }

    public function testConvertAnonymousAccountOAuth2(): void
    {
        // Clean up any existing user with the mock OAuth email to prevent
        // conflicts with parallel tests that also use the mock provider
        $this->deleteUserByEmail('useroauth@localhost.test');

        $session = $this->createAnonymousSession();
        $provider = 'mock';
        $appId = '1';
        $secret = '123456';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $this->assertEquals(200, $response['headers']['status-code']);

        $userId = $response['body']['$id'] ?? '';

        $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $this->getProject()['$id'] . '/oauth2', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
        ]), [
            'provider' => $provider,
            'appId' => $appId,
            'secret' => $secret,
            'enabled' => true,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        // Delete any user with the mock OAuth email right before the OAuth call
        // to minimize the race window with parallel tests
        $this->deleteUserByEmail('useroauth@localhost.test');

        $response = $this->client->call(Client::METHOD_GET, '/account/sessions/oauth2/' . $provider, array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'success' => 'http://localhost/v1/mock/tests/general/oauth2/success',
            'failure' => 'http://localhost/v1/mock/tests/general/oauth2/failure',
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        $sessionCookieKey = 'a_session_' . $this->getProject()['$id'];
        $this->assertArrayHasKey(
            $sessionCookieKey,
            $response['cookies'],
            "Failed asserting that session cookie '$sessionCookieKey' is set. Cookies: " . json_encode($response['cookies'])
        );
        $session = $response['cookies'][$sessionCookieKey];

        $response = $this->client->call(Client::METHOD_GET, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals($response['body']['$id'], $userId);
        $this->assertEquals('User Name', $response['body']['name']);
        $this->assertEquals('useroauth@localhost.test', $response['body']['email']);

        // Since we only support one oauth user, let's also check updateSession here

        $response = $this->client->call(Client::METHOD_GET, '/account/sessions/current', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEmpty($response['body']['secret']);
        $this->assertEquals('123456', $response['body']['providerAccessToken']);
        $this->assertEquals('tuvwxyz', $response['body']['providerRefreshToken']);
        $this->assertGreaterThan(DateTime::addSeconds(new \DateTime(), 14400 - 5), $response['body']['providerAccessTokenExpiry']); // 5 seconds allowed networking delay

        $initialExpiry = $response['body']['providerAccessTokenExpiry'];

        sleep(3);

        $response = $this->client->call(Client::METHOD_PATCH, '/account/sessions/current', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('123456', $response['body']['providerAccessToken']);
        $this->assertEquals('tuvwxyz', $response['body']['providerRefreshToken']);
        $this->assertNotEquals($initialExpiry, $response['body']['providerAccessTokenExpiry']);

        // Clean up - delete the user
        $response = $this->client->call(Client::METHOD_DELETE, '/users/' . $userId, array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]));

        $this->assertEquals(204, $response['headers']['status-code']);
    }

    public function testOAuthUnverifiedEmailCannotLinkToExistingAccount(): void
    {
        $provider = 'mock-unverified';
        $appId = '1';
        $secret = '123456';

        // First, create a user with the same email that the unverified OAuth will try to use
        $email = 'useroauthunverified@localhost.test';
        $password = 'password';

        // Clean up any existing user with this email from parallel tests
        $this->deleteUserByEmail($email);

        $response = $this->client->call(Client::METHOD_POST, '/account', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'userId' => ID::unique(),
            'email' => $email,
            'password' => $password,
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $existingUserId = $response['body']['$id'];

        // Enable the mock-unverified provider
        $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $this->getProject()['$id'] . '/oauth2', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
        ]), [
            'provider' => $provider,
            'appId' => $appId,
            'secret' => $secret,
            'enabled' => true,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        // Attempt OAuth login with unverified email - should fail because existing user has same email
        $response = $this->client->call(Client::METHOD_GET, '/account/sessions/oauth2/' . $provider, array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'success' => 'http://localhost/v1/mock/tests/general/oauth2/success',
            'failure' => 'http://localhost/v1/mock/tests/general/oauth2/failure',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);
        $this->assertEquals('failure', $response['body']['result']);

        // Clean up - delete the user
        $response = $this->client->call(Client::METHOD_DELETE, '/users/' . $existingUserId, array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]));

        $this->assertEquals(204, $response['headers']['status-code']);
    }

    public function testOAuthVerifiedEmailCanLinkToExistingAccount(): void
    {
        $provider = 'mock';
        $appId = '1';
        $secret = '123456';
        $email = 'useroauth@localhost.test';

        // Clean up any existing user with this email from parallel tests
        $this->deleteUserByEmail($email);

        // Create a user with the same email that the verified OAuth will try to use
        $response = $this->client->call(Client::METHOD_POST, '/account', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'userId' => ID::unique(),
            'email' => $email,
            'password' => 'password',
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $existingUserId = $response['body']['$id'];

        // Enable the mock provider
        $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $this->getProject()['$id'] . '/oauth2', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
        ]), [
            'provider' => $provider,
            'appId' => $appId,
            'secret' => $secret,
            'enabled' => true,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        // Attempt OAuth login with verified email - should succeed and link to existing account
        $response = $this->client->call(Client::METHOD_GET, '/account/sessions/oauth2/' . $provider, array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'success' => 'http://localhost/v1/mock/tests/general/oauth2/success',
            'failure' => 'http://localhost/v1/mock/tests/general/oauth2/failure',
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('success', $response['body']['result']);

        // Verify the OAuth identity was linked to the existing user
        $sessionCookieKey = 'a_session_' . $this->getProject()['$id'];
        $session = $response['cookies'][$sessionCookieKey];

        $response = $this->client->call(Client::METHOD_GET, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals($existingUserId, $response['body']['$id']);
        $this->assertEquals($email, $response['body']['email']);

        // Clean up - delete the user
        $response = $this->client->call(Client::METHOD_DELETE, '/users/' . $existingUserId, array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]));

        $this->assertEquals(204, $response['headers']['status-code']);
    }

    public function testGetSessionByID(): void
    {
        $session = $this->createAnonymousSession();

        $response = $this->client->call(Client::METHOD_GET, '/account/sessions/current', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEmpty($response['body']['secret']);
        $this->assertEquals('anonymous', $response['body']['provider']);

        $sessionID = $response['body']['$id'];

        $response = $this->client->call(Client::METHOD_GET, '/account/sessions/' . $sessionID, array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEmpty($response['body']['secret']);
        $this->assertEquals('anonymous', $response['body']['provider']);

        $response = $this->client->call(Client::METHOD_GET, '/account/sessions/97823askjdkasd80921371980', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $this->assertEquals(404, $response['headers']['status-code']);
    }

    public function testUpdateAccountNameSearch(): void
    {
        $data = $this->setupAccountWithUpdatedName();
        $id = $data['id'];
        $newName = 'Lorem';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/users', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'search' => $newName,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['users']);
        // In parallel execution, there may be more users with name 'Lorem'
        $this->assertGreaterThanOrEqual(1, count($response['body']['users']));
        // Find our user in the results
        $foundUser = null;
        foreach ($response['body']['users'] as $user) {
            if ($user['$id'] === $id) {
                $foundUser = $user;
                break;
            }
        }
        $this->assertNotNull($foundUser, 'User should be found in search results');
        $this->assertEquals($newName, $foundUser['name']);

        $response = $this->client->call(Client::METHOD_GET, '/users', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'search' => $id,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['users']);
        $this->assertCount(1, $response['body']['users']);
        $this->assertEquals($newName, $response['body']['users'][0]['name']);
    }

    public function testUpdateAccountEmailSearch(): void
    {
        $data = $this->setupAccountWithUpdatedEmail();
        $id = $data['id'];
        $email = $data['email'];

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/users', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'search' => '"' . $email . '"',

        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['users']);
        $this->assertCount(1, $response['body']['users']);
        $this->assertEquals($response['body']['users'][0]['email'], $email);

        $response = $this->client->call(Client::METHOD_GET, '/users', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'search' => $id,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['users']);
        $this->assertCount(1, $response['body']['users']);
        $this->assertEquals($response['body']['users'][0]['email'], $email);
    }

    public function testCreatePhone(): void
    {
        $number = '+123456789';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_POST, '/account/tokens/phone', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'userId' => ID::unique(),
            'phone' => $number,
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEmpty($response['body']['secret']);
        $this->assertTrue((new DatetimeValidator())->isValid($response['body']['expire']));

        $userId = $response['body']['userId'];

        $smsRequest = $this->getLastRequestForProject(
            $this->getProject()['$id'],
            Scope::REQUEST_TYPE_SMS,
            [
                'header_X-Username' => 'username',
                'header_X-Key' => 'password',
                'method' => 'POST',
            ],
            probe: function (array $request) use ($number) {
                $this->assertEquals('Appwrite Mock Message Sender', $request['headers']['User-Agent'] ?? null);
                $this->assertEquals('username', $request['headers']['X-Username'] ?? null);
                $this->assertEquals('password', $request['headers']['X-Key'] ?? null);
                $this->assertEquals('POST', $request['method'] ?? null);
                $this->assertEquals('+123456789', $request['data']['from'] ?? null);
                $this->assertEquals($number, $request['data']['to'] ?? null);
            }
        );

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_POST, '/account/tokens/phone', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'userId' => ID::unique()
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);
    }

    public function testCreateSessionWithPhone(): void
    {
        $data = $this->setupPhoneAccount();
        $id = $data['id'];
        $token = explode(" ", $data['token'])[0] ?? '';
        $number = $data['number'];

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_PUT, '/account/sessions/phone', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'userId' => ID::custom('ewewe'),
            'secret' => $token,
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_PUT, '/account/sessions/phone', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'userId' => $id,
            'secret' => 'sdasdasdasd',
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_PUT, '/account/sessions/phone', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'userId' => $id,
            'secret' => $token,
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertIsArray($response['body']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertNotEmpty($response['body']['userId']);

        $session = $response['cookies']['a_session_' . $this->getProject()['$id']];

        $response = $this->client->call(Client::METHOD_GET, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals(true, (new DatetimeValidator())->isValid($response['body']['registration']));
        $this->assertEquals($response['body']['phone'], $number);
        $this->assertTrue($response['body']['phoneVerification']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_PUT, '/account/sessions/phone', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'userId' => $id,
            'secret' => $token,
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);
    }

    public function testConvertPhoneToPassword(): void
    {
        $data = $this->setupPhoneSession();
        $session = $data['session'];
        $email = uniqid() . 'new@localhost.test';
        $password = 'new-password';

        $response = $this->client->call(Client::METHOD_PATCH, '/account/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'email' => $email,
            'password' => $password,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertIsArray($response['body']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertTrue((new DatetimeValidator())->isValid($response['body']['registration']));
        $this->assertEquals($response['body']['email'], $email);

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'password' => $password,
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
    }

    public function testUpdatePhone(): void
    {
        $data = $this->setupPhoneConvertedToPassword();
        $newPhone = '+456' . substr(str_replace('.', '', microtime(true)) . getmypid() . random_int(100, 999), -8);
        $session = $data['session'];

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/account/phone', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'phone' => $newPhone,
            'password' => 'new-password'
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertIsArray($response['body']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertTrue((new DatetimeValidator())->isValid($response['body']['registration']));
        $this->assertEquals($response['body']['phone'], $newPhone);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/account/phone', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]));

        $this->assertEquals(401, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_PATCH, '/account/phone', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), []);

        $this->assertEquals(400, $response['headers']['status-code']);
    }

    public function testCreateSession(): void
    {
        $data = $this->setupPhoneUpdated();

        $response = $this->client->call(Client::METHOD_POST, '/users/' . $data['id'] . '/tokens', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'expire' => 60
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        $userId = $response['body']['userId'];
        $secret = $response['body']['secret'];

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/token', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'userId' => $userId,
            'secret' => $secret
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertEquals($data['id'], $response['body']['userId']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertNotEmpty($response['body']['expire']);
        $this->assertEmpty($response['body']['secret']);
        $this->assertEquals('browser', $response['body']['clientType']);
        $this->assertEquals('CH', $response['body']['clientCode']);
        $this->assertEquals('Chrome', $response['body']['clientName']);

        // Forwarded User Agent with API Key
        $response = $this->client->call(Client::METHOD_POST, '/users/' . $data['id'] . '/tokens', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'expire' => 60
        ]);

        $userId = $response['body']['userId'];
        $secret = $response['body']['secret'];

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/token', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
            'x-forwarded-user-agent' => 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/114.0.0.0 Mobile Safari/537.36'
        ], [
            'userId' => $userId,
            'secret' => $secret
        ]);

        $this->assertEquals('browser', $response['body']['clientType']);
        $this->assertEquals('CM', actual: $response['body']['clientCode']);
        $this->assertEquals('Chrome Mobile', $response['body']['clientName']);

        // Forwarded User Agent without API Key
        $response = $this->client->call(Client::METHOD_POST, '/users/' . $data['id'] . '/tokens', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'expire' => 60
        ]);

        $userId = $response['body']['userId'];
        $secret = $response['body']['secret'];

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/token', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-forwarded-user-agent' => 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/114.0.0.0 Mobile Safari/537.36'
        ], [
            'userId' => $userId,
            'secret' => $secret
        ]);

        $this->assertEquals('browser', $response['body']['clientType']);
        $this->assertEquals('CH', $response['body']['clientCode']);
        $this->assertEquals('Chrome', $response['body']['clientName']);

        /**
         * Test for FAILURE
         */
        // Invalid userId
        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/token', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'userId' => ID::custom('ewewe'),
            'secret' => $secret,
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);

        // Invalid secret
        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/token', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'userId' => $userId,
            'secret' => '123456',
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);
    }

    public function testPhoneVerification(): void
    {
        $data = $this->setupPhoneUpdated();
        $session = $data['session'];

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_POST, '/account/verification/phone', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertNotEmpty($response['body']['$createdAt']);
        $this->assertEmpty($response['body']['secret']);
        $this->assertTrue((new DatetimeValidator())->isValid($response['body']['expire']));

        $tokenCreatedAt = $response['body']['$createdAt'];

        $phone = $data['phone'] ?? '';
        $smsQuery = [
            'header_X-Username' => 'username',
            'header_X-Key' => 'password',
            'method' => 'POST',
        ];

        $smsRequest = $this->getLastRequestForProject(
            $this->getProject()['$id'],
            Scope::REQUEST_TYPE_SMS,
            $smsQuery,
            probe: function (array $request) use ($tokenCreatedAt, $phone) {
                $this->assertArrayHasKey('data', $request);
                $this->assertArrayHasKey('time', $request);
                $this->assertArrayHasKey('message', $request['data'], "Last request missing message: " . \json_encode($request));
                if (!empty($phone)) {
                    $this->assertEquals($phone, $request['data']['to'] ?? null);
                }

                // Ensure we are not using token from last sms login
                $tokenRecievedAt = $request['time'];
                $this->assertGreaterThan($tokenCreatedAt, $tokenRecievedAt);
            }
        );

        /**
         * Test for FAILURE
         */

        // disable phone sessions
        $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $this->getProject()['$id'] . '/auth/phone', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
        ]), [
            'status' => false,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(false, $response['body']['authPhone']);

        $response = $this->client->call(Client::METHOD_POST, '/account/verification/phone', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $this->assertEquals(501, $response['headers']['status-code']);
        $this->assertEquals("Phone authentication is disabled for this project", $response['body']['message']);

        // Re-enable phone auth so other parallel tests are not affected
        $this->ensurePhoneAuthEnabled();
    }

    public function testUpdatePhoneVerification(): void
    {
        $data = $this->setupPhoneVerification();
        $id = $data['id'];
        $session = $data['session'];
        $secret = $data['token'];

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_PUT, '/account/verification/phone', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'userId' => $id,
            'secret' => $secret,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_PUT, '/account/verification/phone', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'userId' => ID::custom('ewewe'),
            'secret' => $secret,
        ]);

        $this->assertEquals(404, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_PUT, '/account/verification/phone', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'userId' => $id,
            'secret' => '999999',
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);
    }

    public function testCreateMagicUrl(): void
    {
        // Use uniqid for uniqueness in parallel test execution
        $email = 'magic-' . uniqid() . '-' . \time() . '@appwrite.io';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_POST, '/account/tokens/magic-url', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'userId' => ID::unique(),
            'email' => $email,
            // 'url' => 'http://localhost/magiclogin',
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEmpty($response['body']['secret']);
        $this->assertEmpty($response['body']['phrase']);
        $this->assertTrue((new DatetimeValidator())->isValid($response['body']['expire']));

        $userId = $response['body']['userId'];

        $lastEmail = $this->getLastEmailByAddress($email);
        $this->assertNotEmpty($lastEmail, 'Email not found for address: ' . $email);
        $this->assertEquals($this->getProject()['name'] . ' Login', $lastEmail['subject']);
        $this->assertStringContainsStringIgnoringCase('Sign in to '. $this->getProject()['name'] . ' with your secure link. Expires in 1 hour.', $lastEmail['text']);
        $this->assertStringNotContainsStringIgnoringCase('security phrase', $lastEmail['text']);

        $token = substr($lastEmail['text'], strpos($lastEmail['text'], '&secret=', 0) + 8, 64);

        $expireTime = strpos($lastEmail['text'], 'expire=' . urlencode($response['body']['expire']), 0);

        $this->assertNotFalse($expireTime);

        $secretTest = strpos($lastEmail['text'], 'secret=' . $response['body']['secret'], 0);

        $this->assertNotFalse($secretTest);

        $userIDTest = strpos($lastEmail['text'], 'userId=' . $response['body']['userId'], 0);

        $this->assertNotFalse($userIDTest);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_POST, '/account/tokens/magic-url', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'userId' => ID::unique(),
            'email' => $email,
            'url' => 'localhost/magiclogin',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/account/tokens/magic-url', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'userId' => ID::unique(),
            'email' => $email,
            'url' => 'http://remotehost/magiclogin',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/account/tokens/magic-url', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/magic-url', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'userId' => ID::unique(),
            'email' => $email,
            'phrase' => true
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertNotEmpty($response['body']['phrase']);

        $lastEmail = $this->getLastEmailByAddress($email);
        $this->assertNotEmpty($lastEmail, 'Email not found for address: ' . $email);
        $this->assertStringContainsStringIgnoringCase($response['body']['phrase'], $lastEmail['text']);
    }

    public function testCreateSessionWithMagicUrl(): void
    {
        $projectId = $this->getProject()['$id'];

        // Get a fresh magic URL token - the cached one may have been consumed by setupMagicUrlSession
        $email = \uniqid() . 'magicurl@localhost.test';

        $tokenResponse = $this->client->call(Client::METHOD_POST, '/account/tokens/magic-url', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ]), [
            'userId' => ID::unique(),
            'email' => $email,
        ]);

        $this->assertEquals(201, $tokenResponse['headers']['status-code']);
        $id = $tokenResponse['body']['userId'];

        $lastEmail = $this->getLastEmailByAddress($email);
        $this->assertNotEmpty($lastEmail, 'Email not found for address: ' . $email);
        $token = substr($lastEmail['text'], strpos($lastEmail['text'], '&secret=', 0) + 8, 64);

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_PUT, '/account/sessions/magic-url', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ]), [
            'userId' => $id,
            'secret' => $token,
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertIsArray($response['body']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertNotEmpty($response['body']['userId']);
        $this->assertEmpty($response['body']['secret']);

        $sessionId = $response['body']['$id'];
        $session = $response['cookies']['a_session_' . $this->getProject()['$id']];

        $response = $this->client->call(Client::METHOD_GET, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertTrue((new DatetimeValidator())->isValid($response['body']['registration']));
        $this->assertEquals($response['body']['email'], $email);
        $this->assertTrue($response['body']['emailVerification']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_PUT, '/account/sessions/magic-url', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'userId' => ID::custom('ewewe'),
            'secret' => $token,
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_PUT, '/account/sessions/magic-url', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'userId' => $id,
            'secret' => 'sdasdasdasd',
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);
    }

    public function testUpdateAccountPasswordWithMagicUrl(): void
    {
        $data = $this->setupMagicUrlSession();
        $email = $data['email'];
        $session = $data['session'];

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/account/password', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'password' => 'new-password'
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertIsArray($response['body']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertTrue((new DatetimeValidator())->isValid($response['body']['registration']));
        $this->assertEquals($response['body']['email'], $email);

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'password' => 'new-password',
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertEmpty($response['body']['secret']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/account/password', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]));

        $this->assertEquals(401, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_PATCH, '/account/password', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), []);

        $this->assertEquals(400, $response['headers']['status-code']);

        /**
         * Existing user tries to update password by passing wrong old password -> SHOULD FAIL
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/account/password', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'password' => 'new-password',
            'oldPassword' => 'wrong-password',
        ]);
        $this->assertEquals(401, $response['headers']['status-code']);

        /**
         * Existing user tries to update password without passing old password -> SHOULD FAIL
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/account/password', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'password' => 'new-password'
        ]);
        $this->assertEquals(401, $response['headers']['status-code']);
    }

    public function testCreatePushTarget(): void
    {
        $response = $this->client->call(Client::METHOD_POST, '/account/targets/push', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()), [
            'targetId' => ID::unique(),
            'identifier' => 'test-identifier',
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals('test-identifier', $response['body']['identifier']);
    }

    public function testUpdatePushTarget(): void
    {
        $response = $this->client->call(Client::METHOD_POST, '/account/targets/push', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'targetId' => ID::unique(),
            'identifier' => 'test-identifier-2',
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals('test-identifier-2', $response['body']['identifier']);

        $response = $this->client->call(Client::METHOD_PUT, '/account/targets/'. $response['body']['$id'] .'/push', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'identifier' => 'test-identifier-updated',
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('test-identifier-updated', $response['body']['identifier']);
        $this->assertEquals(false, $response['body']['expired']);
    }

    public function testMFARecoveryCodeChallenge(): void
    {
        // Generate recovery codes using existing authenticated session
        $response = $this->client->call(Client::METHOD_POST, '/account/mfa/recovery-codes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['recoveryCodes']);
        $recoveryCodes = $response['body']['recoveryCodes'];
        $this->assertGreaterThan(0, count($recoveryCodes));

        // Create recovery code challenge
        $challenge = $this->client->call(Client::METHOD_POST, '/account/mfa/challenge', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'factor' => 'recoveryCode'
        ]);

        $this->assertEquals(201, $challenge['headers']['status-code']);
        $this->assertNotEmpty($challenge['body']['$id']);
        $challengeId = $challenge['body']['$id'];

        // Test SUCCESS: Verify with valid recovery code (this tests the bug fix)
        $verification = $this->client->call(Client::METHOD_PUT, '/account/mfa/challenge', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'challengeId' => $challengeId,
            'otp' => $recoveryCodes[0]
        ]);

        $this->assertEquals(200, $verification['headers']['status-code']);
        $this->assertArrayHasKey('factors', $verification['body']);
        $this->assertContains('recoveryCode', $verification['body']['factors']);

        // Test that the code was consumed (can't use again)
        $challenge2 = $this->client->call(Client::METHOD_POST, '/account/mfa/challenge', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'factor' => 'recoveryCode'
        ]);

        $this->assertEquals(201, $challenge2['headers']['status-code']);

        $verification2 = $this->client->call(Client::METHOD_PUT, '/account/mfa/challenge', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'challengeId' => $challenge2['body']['$id'],
            'otp' => $recoveryCodes[0] // Same code should fail
        ]);

        $this->assertEquals(401, $verification2['headers']['status-code']);

        // Test FAILURE: Invalid recovery code
        $challenge3 = $this->client->call(Client::METHOD_POST, '/account/mfa/challenge', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'factor' => 'recoveryCode'
        ]);

        $this->assertEquals(201, $challenge3['headers']['status-code']);

        $verification3 = $this->client->call(Client::METHOD_PUT, '/account/mfa/challenge', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'challengeId' => $challenge3['body']['$id'],
            'otp' => 'invalid-code-123'
        ]);

        $this->assertEquals(401, $verification3['headers']['status-code']);
    }
}
