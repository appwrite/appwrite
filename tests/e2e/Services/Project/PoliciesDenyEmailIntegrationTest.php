<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Project;

use Appwrite\Extend\Exception;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Helpers\ID;

final class PoliciesDenyEmailIntegrationTest extends Scope
{
    use ProjectCustom;
    use SideServer;

    private const PASSWORD = 'password123';

    /**
     * @return \Iterator<string, array{path: string, blockedDomain: string, usesAlias: bool, errorType: string}>
     */
    public static function provideEmailPolicies(): \Iterator
    {
        yield 'deny aliased email' => [
            'path' => '/project/policies/deny-aliased-email',
            'blockedDomain' => 'gmail.com',
            'usesAlias' => true,
            'errorType' => Exception::USER_EMAIL_NOT_CANONICAL,
        ];
        yield 'deny corporate email' => [
            'path' => '/project/policies/deny-corporate-email',
            'blockedDomain' => 'gmail.com',
            'usesAlias' => false,
            'errorType' => Exception::USER_EMAIL_NOT_CORPORATE,
        ];
        yield 'deny disposable email' => [
            'path' => '/project/policies/deny-disposable-email',
            'blockedDomain' => 'mailinator.com',
            'usesAlias' => false,
            'errorType' => Exception::USER_EMAIL_DISPOSABLE,
        ];
        yield 'deny free email' => [
            'path' => '/project/policies/deny-free-email',
            'blockedDomain' => 'gmail.com',
            'usesAlias' => false,
            'errorType' => Exception::USER_EMAIL_FREE,
        ];
    }

    #[DataProvider('provideEmailPolicies')]
    public function testEmailPolicyControlsSignup(string $path, string $blockedDomain, bool $usesAlias, string $errorType): void
    {
        $project = $this->getProject(true);

        $this->updateEmailPolicy($project, $path, false);

        $allowedSignup = $this->createAccount($project['$id'], $this->blockedEmail('allowed', $blockedDomain, $usesAlias));
        $this->assertSame(201, $allowedSignup['headers']['status-code']);

        $this->updateEmailPolicy($project, $path, true);

        $blockedSignup = $this->createAccount($project['$id'], $this->blockedEmail('blocked', $blockedDomain, $usesAlias));
        $this->assertSame(400, $blockedSignup['headers']['status-code']);
        $this->assertSame($errorType, $blockedSignup['body']['type']);
    }

    #[DataProvider('provideEmailPolicies')]
    public function testEmailPolicyControlsEmailUpdates(string $path, string $blockedDomain, bool $usesAlias, string $errorType): void
    {
        $project = $this->getProject(true);

        $this->updateEmailPolicy($project, $path, true);

        $email = $this->uniqueEmail('existing', 'appwrite.io');
        $this->createAccount($project['$id'], $email);
        $session = $this->createSession($project['$id'], $email);

        $blockedUpdate = $this->updateAccountEmail($project['$id'], $session, $this->blockedEmail('blocked-update', $blockedDomain, $usesAlias));
        $this->assertSame(400, $blockedUpdate['headers']['status-code']);
        $this->assertSame($errorType, $blockedUpdate['body']['type']);

        $allowedEmail = $this->uniqueEmail('allowed-update', 'imagine.dev');
        $allowedUpdate = $this->updateAccountEmail($project['$id'], $session, $allowedEmail);

        $this->assertSame(200, $allowedUpdate['headers']['status-code']);
        $this->assertSame($allowedEmail, $allowedUpdate['body']['email']);
    }

    public function testCorporateEmailPolicyBlocksDisposableAndFreeEmails(): void
    {
        $project = $this->getProject(true);

        $this->updateEmailPolicy($project, '/project/policies/deny-corporate-email', true);

        $blockedDisposable = $this->createAccount($project['$id'], $this->uniqueEmail('blocked', 'mailinator.com'));
        $this->assertSame(400, $blockedDisposable['headers']['status-code']);
        $this->assertSame(Exception::USER_EMAIL_NOT_CORPORATE, $blockedDisposable['body']['type']);

        $blockedFree = $this->createAccount($project['$id'], $this->uniqueEmail('blocked-free', 'outlook.com'));
        $this->assertSame(400, $blockedFree['headers']['status-code']);
        $this->assertSame(Exception::USER_EMAIL_NOT_CORPORATE, $blockedFree['body']['type']);

        $allowed = $this->createAccount($project['$id'], $this->uniqueEmail('allowed', 'appwrite.io'));
        $this->assertSame(201, $allowed['headers']['status-code']);
    }

    /**
     * @param array<string, string> $project
     */
    private function updateEmailPolicy(array $project, string $path, bool $enabled): void
    {
        $response = $this->client->call(Client::METHOD_PATCH, $path, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $project['$id'],
            'x-appwrite-key' => $project['apiKey'],
            'x-appwrite-response-format' => '1.9.4',
        ], [
            'enabled' => $enabled,
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
    }

    private function createAccount(string $projectId, string $email): array
    {
        return $this->client->call(Client::METHOD_POST, '/account', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], [
            'userId' => ID::unique(),
            'email' => $email,
            'password' => self::PASSWORD,
            'name' => 'Policy User',
        ]);
    }

    private function createSession(string $projectId, string $email): string
    {
        $session = $this->client->call(Client::METHOD_POST, '/account/sessions/email', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], [
            'email' => $email,
            'password' => self::PASSWORD,
        ]);

        $this->assertSame(201, $session['headers']['status-code']);

        return $session['cookies']['a_session_' . $projectId];
    }

    private function updateAccountEmail(string $projectId, string $session, string $email): array
    {
        return $this->client->call(Client::METHOD_PATCH, '/account/email', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_' . $projectId . '=' . $session,
        ], [
            'email' => $email,
            'password' => self::PASSWORD,
        ]);
    }

    private function uniqueEmail(string $prefix, string $domain): string
    {
        return $prefix . '-' . ID::unique() . '@' . $domain;
    }

    private function blockedEmail(string $prefix, string $domain, bool $usesAlias): string
    {
        if ($usesAlias) {
            return $prefix . '-' . ID::unique() . '+alias@' . $domain;
        }

        return $this->uniqueEmail($prefix, $domain);
    }
}
