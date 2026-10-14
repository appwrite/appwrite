<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Project;

use Tests\E2E\Client;
use Utopia\Database\Helpers\ID;

trait PasswordDenyListBase
{
    abstract protected function passwordPolicy(): string;

    public function testPasswordDenyListCreationAndUpdates(): void
    {
        $this->withPasswordDenyList(function (): void {
            $blocked = $this->blockedPassword();
            $this->setDenyList(false);

            foreach (['/account', '/users'] as $path) {
                $headers = $path === '/account' ? $this->passwordClientHeaders() : $this->passwordServerHeaders();
                $params = ['userId' => ID::unique(), 'email' => ID::unique() . '@localhost.test', 'password' => $blocked];
                $created = $this->client->call(Client::METHOD_POST, $path, $headers, $params);
                $this->assertSame(201, $created['headers']['status-code']);

                $this->setDenyList(true);
                $response = $this->client->call(Client::METHOD_POST, $path, $headers, array_replace($params, ['userId' => ID::unique(), 'email' => ID::unique() . '@localhost.test']));
                $this->assertPasswordRejected($response);

                $session = $this->passwordSession($params['email'], $blocked);
                $sessionHeaders = $this->passwordSessionHeaders($session);
                $updatePath = $path === '/account' ? '/account/password' : '/users/' . $params['userId'] . '/password';
                $updateHeaders = $path === '/account' ? $sessionHeaders : $headers;
                $before = $this->client->call(Client::METHOD_GET, '/users/' . $params['userId'], $this->passwordServerHeaders())['body']['passwordUpdate'];

                $response = $this->client->call(Client::METHOD_PATCH, $updatePath, $updateHeaders, ['password' => $blocked, 'oldPassword' => $blocked]);
                $this->assertPasswordRejected($response);
                $after = $this->client->call(Client::METHOD_GET, '/users/' . $params['userId'], $this->passwordServerHeaders());
                $this->assertSame($before, $after['body']['passwordUpdate']);
                $this->passwordSession($params['email'], $blocked);

                foreach (['email' => ID::unique() . '@localhost.test', 'phone' => $this->passwordPhone()] as $field => $value) {
                    $response = $this->client->call(Client::METHOD_PATCH, '/account/' . $field, $sessionHeaders, [$field => $value, 'password' => $blocked]);
                    $this->assertSame(200, $response['headers']['status-code']);
                    $this->assertSame($value, $response['body'][$field]);
                    $params[$field] = $value;
                }

                $allowed = $this->passwordPolicy() === 'password-dictionary' ? 'MyAcmeDefault123!' : 'Allowed-' . ID::unique();
                $response = $this->client->call(Client::METHOD_PATCH, $updatePath, $updateHeaders, ['password' => $allowed, 'oldPassword' => $blocked]);
                $this->assertSame(200, $response['headers']['status-code']);
                $response = $this->client->call(Client::METHOD_PATCH, $updatePath, $updateHeaders, ['password' => $blocked, 'oldPassword' => $allowed]);
                $this->assertPasswordRejected($response);
                $this->passwordSession($params['email'], $allowed);
                $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', $this->passwordServerHeaders(), ['email' => $params['email'], 'password' => $blocked]);
                $this->assertSame(401, $response['headers']['status-code']);
                $response = $this->client->call(Client::METHOD_POST, $path, $headers, ['userId' => ID::unique(), 'email' => ID::unique() . '@localhost.test', 'password' => $allowed]);
                $this->assertSame(201, $response['headers']['status-code']);
                $this->setDenyList(false);
            }
        });
    }

    public function testPasswordDenyListFirstPassword(): void
    {
        $this->withPasswordDenyList(function (): void {
            foreach (['email', 'phone'] as $field) {
                $session = $this->client->call(Client::METHOD_POST, '/account/sessions/anonymous', $this->passwordClientHeaders());
                $this->assertSame(201, $session['headers']['status-code']);
                $headers = $this->passwordSessionHeaders($session);
                $value = $field === 'email' ? ID::unique() . '@localhost.test' : $this->passwordPhone();
                $params = [$field => $value, 'password' => $this->blockedPassword()];

                $response = $this->client->call(Client::METHOD_PATCH, '/account/' . $field, $headers, $params);
                $this->assertPasswordRejected($response);
                $account = $this->client->call(Client::METHOD_GET, '/account', $headers);
                $this->assertSame(200, $account['headers']['status-code']);
                $this->assertEmpty($account['body']['passwordUpdate']);
                $this->assertEmpty($account['body'][$field]);

                $params['password'] = 'Allowed-' . ID::unique();
                $response = $this->client->call(Client::METHOD_PATCH, '/account/' . $field, $headers, $params);
                $this->assertSame(200, $response['headers']['status-code']);
                $this->assertNotEmpty($response['body']['passwordUpdate']);
                $this->assertSame($value, $response['body'][$field]);
            }
        });
    }

    public function testPasswordDenyListRecovery(): void
    {
        $this->withPasswordDenyList(function (): void {
            foreach (['/account/recovery', '/account/recovery/otp'] as $path) {
                $userId = ID::unique();
                $email = ID::unique() . '@localhost.test';
                $name = 'Profile-' . ID::unique();
                $phone = $this->passwordPhone();
                $password = 'Original-' . ID::unique();
                $created = $this->client->call(Client::METHOD_POST, '/users', $this->passwordServerHeaders(), ['userId' => $userId, 'email' => $email, 'phone' => $phone, 'name' => $name, 'password' => $password]);
                $this->assertSame(201, $created['headers']['status-code']);
                $before = $created['body']['passwordUpdate'];
                $recovery = $this->client->call(Client::METHOD_POST, $path, $this->passwordClientHeaders(), ['email' => $email, 'url' => 'http://localhost/recovery']);
                $this->assertSame(201, $recovery['headers']['status-code']);
                $mail = $this->getLastEmailByAddress($email);
                $secret = '';
                if ($path === '/account/recovery') {
                    $secret = $this->extractQueryParamsFromEmailLink($mail['html'])['secret'];
                } else {
                    foreach (explode(' ', str_replace(["\r", "\n", "\t"], ' ', $mail['text'])) as $word) {
                        if (strlen($word) === 6 && ctype_digit($word)) {
                            $secret = $word;
                            break;
                        }
                    }
                }
                $this->assertNotEmpty($secret);

                $rejected = [$this->blockedPassword()];
                if ($this->passwordPolicy() === 'password-personal-data') {
                    foreach ([$userId, $email, explode('@', $email)[0], $name, $phone, substr($phone, 1)] as $field) {
                        $rejected[] = 'Prefix-' . strtoupper($field) . '-Suffix';
                    }
                    $invalid = $this->client->call(Client::METHOD_PUT, $path, $this->passwordClientHeaders(), ['userId' => $userId, 'secret' => 'invalid', 'password' => 'Prefix-' . $name]);
                    $this->assertSame(401, $invalid['headers']['status-code']);
                    $this->assertSame('user_invalid_token', $invalid['body']['type']);
                }

                foreach ($rejected as $blocked) {
                    $response = $this->client->call(Client::METHOD_PUT, $path, $this->passwordClientHeaders(), ['userId' => $userId, 'secret' => $secret, 'password' => $blocked]);
                    $this->assertPasswordRejected($response);
                    $user = $this->client->call(Client::METHOD_GET, '/users/' . $userId, $this->passwordServerHeaders());
                    $this->assertSame($before, $user['body']['passwordUpdate']);
                    $this->passwordSession($email, $password);
                }

                $allowed = 'Recovered-' . ID::unique();
                $response = $this->client->call(Client::METHOD_PUT, $path, $this->passwordClientHeaders(), ['userId' => $userId, 'secret' => $secret, 'password' => $allowed]);
                $this->assertSame(200, $response['headers']['status-code']);
                $this->passwordSession($email, $allowed);
                $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', $this->passwordServerHeaders(), ['email' => $email, 'password' => $password]);
                $this->assertSame(401, $response['headers']['status-code']);
                $response = $this->client->call(Client::METHOD_PUT, $path, $this->passwordClientHeaders(), ['userId' => $userId, 'secret' => $secret, 'password' => 'Another-' . ID::unique()]);
                $this->assertSame(401, $response['headers']['status-code']);
                $this->assertSame('user_invalid_token', $response['body']['type']);
            }
        });
    }

    public function testPasswordDenyListPasswordlessAndImportedHashes(): void
    {
        $this->withPasswordDenyList(function (): void {
            $created = $this->client->call(Client::METHOD_POST, '/users', $this->passwordServerHeaders(), ['userId' => ID::unique(), 'email' => ID::unique() . '@localhost.test']);
            $this->assertSame(201, $created['headers']['status-code']);
            $this->assertEmpty($created['body']['passwordUpdate']);

            $password = 'Imported-' . ID::unique();
            $hash = hash('sha256', $password);
            $term = substr($hash, 0, 8);
            $this->setDenyList(true, [$term, $hash]);
            $email = ID::unique() . '@localhost.test';
            $created = $this->client->call(Client::METHOD_POST, '/users/sha', $this->passwordServerHeaders(), ['userId' => ID::unique(), 'email' => $email, 'password' => $hash, 'passwordVersion' => 'sha256']);
            $this->assertSame(201, $created['headers']['status-code']);
            $this->passwordSession($email, $password);

            if ($this->passwordPolicy() === 'password-personal-data') {
                $created = $this->client->call(Client::METHOD_POST, '/users/sha', $this->passwordServerHeaders(), ['userId' => ID::unique(), 'email' => ID::unique() . '@localhost.test', 'password' => $hash, 'passwordVersion' => 'sha256', 'name' => $term]);
                $this->assertPasswordRejected($created);
            }
        });
    }

    public function testPasswordDenyListIsolation(): void
    {
        $this->withPasswordDenyList(function (): void {
            $policy = $this->passwordPolicy();
            $other = $policy === 'password-dictionary' ? 'password-personal-data' : 'password-dictionary';
            $this->setDenyList(false);
            $response = $this->client->call(Client::METHOD_PATCH, '/project/policies/' . $other, $this->passwordServerHeaders(), ['enabled' => true, 'denyList' => ['IndependentTerm']]);
            $this->assertSame(200, $response['headers']['status-code']);
            $response = $this->client->call(Client::METHOD_POST, '/users', $this->passwordServerHeaders(), ['userId' => ID::unique(), 'email' => ID::unique() . '@localhost.test', 'password' => $this->blockedPassword()]);
            $this->assertSame(201, $response['headers']['status-code']);
            $this->setDenyList(true);
            $response = $this->client->call(Client::METHOD_GET, '/project/policies/' . $other, $this->passwordServerHeaders());
            $this->assertSame(['IndependentTerm'], $response['body']['denyList']);

            $project = $this->getProject(true);
            $headers = array_replace($this->passwordServerHeaders(), ['x-appwrite-project' => $project['$id'], 'x-appwrite-key' => $project['apiKey']]);
            $response = $this->client->call(Client::METHOD_GET, '/project/policies/' . $policy, $headers);
            $this->assertSame(200, $response['headers']['status-code']);
            $this->assertSame([], $response['body']['denyList']);
            $response = $this->client->call(Client::METHOD_POST, '/users', $headers, ['userId' => ID::unique(), 'email' => ID::unique() . '@localhost.test', 'password' => $this->blockedPassword()]);
            $this->assertSame(201, $response['headers']['status-code']);
            $response = $this->client->call(Client::METHOD_POST, '/users', $this->passwordServerHeaders(), ['userId' => ID::unique(), 'email' => ID::unique() . '@localhost.test', 'password' => $this->blockedPassword()]);
            $this->assertPasswordRejected($response);
        });
    }

    private function withPasswordDenyList(callable $test): void
    {
        $settings = [
            'password-dictionary' => ['enabled' => false, 'denyList' => []],
            'password-personal-data' => ['enabled' => false, 'denyList' => []],
            'password-strength' => ['min' => 8, 'uppercase' => false, 'lowercase' => false, 'number' => false, 'symbols' => false],
            'password-history' => ['total' => null],
            'password-pwned' => ['enabled' => false, 'sessions' => false, 'users' => false],
            'password-rotation' => ['enabled' => false],
            'session-invalidation' => ['enabled' => false],
        ];
        $original = [];
        foreach ($settings as $policy => $params) {
            $response = $this->client->call(Client::METHOD_GET, '/project/policies/' . $policy, $this->passwordServerHeaders());
            $this->assertSame(200, $response['headers']['status-code']);
            $original[$policy] = array_intersect_key($response['body'], $params);
            if ($policy === 'password-history' && $original[$policy]['total'] === 0) {
                $original[$policy]['total'] = null;
            }
        }

        try {
            foreach ($settings as $policy => $params) {
                $response = $this->client->call(Client::METHOD_PATCH, '/project/policies/' . $policy, $this->passwordServerHeaders(), $params);
                $this->assertSame(200, $response['headers']['status-code'], $policy . ': ' . json_encode($response['body']));
            }
            $this->setDenyList(true);
            $test();
        } finally {
            foreach ($original as $policy => $params) {
                $response = $this->client->call(Client::METHOD_PATCH, '/project/policies/' . $policy, $this->passwordServerHeaders(), $params);
                $this->assertSame(200, $response['headers']['status-code']);
            }
        }
    }

    private function setDenyList(bool $enabled, ?array $denyList = null): void
    {
        $response = $this->client->call(Client::METHOD_PATCH, '/project/policies/' . $this->passwordPolicy(), $this->passwordServerHeaders(), ['enabled' => $enabled, 'denyList' => $denyList ?? ($this->passwordPolicy() === 'password-dictionary' ? ['AcmeDefault123!'] : ['appwrite'])]);
        $this->assertSame(200, $response['headers']['status-code']);
    }

    private function blockedPassword(): string
    {
        return $this->passwordPolicy() === 'password-dictionary' ? 'AcmeDefault123!' : 'MyAppWrite2026!';
    }

    private function assertPasswordRejected(array $response): void
    {
        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame($this->passwordPolicy() === 'password-dictionary' ? 'general_argument_invalid' : 'password_personal_data', $response['body']['type']);
    }

    private function passwordSession(string $email, string $password): array
    {
        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', $this->passwordClientHeaders(), ['email' => $email, 'password' => $password]);
        $this->assertSame(201, $response['headers']['status-code']);

        return $response;
    }

    private function passwordSessionHeaders(array $session): array
    {
        $cookie = 'a_session_' . $this->getProject()['$id'];

        return array_merge($this->passwordClientHeaders(), ['cookie' => $cookie . '=' . $session['cookies'][$cookie]]);
    }

    private function passwordServerHeaders(): array
    {
        return array_merge($this->passwordClientHeaders(), $this->getHeaders());
    }

    private function passwordClientHeaders(): array
    {
        return ['origin' => 'http://localhost', 'content-type' => 'application/json', 'x-appwrite-project' => $this->getProject()['$id']];
    }

    private function passwordPhone(): string
    {
        return '+1202' . (string) random_int(1000000, 9999999);
    }
}
