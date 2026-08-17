<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Project;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideConsole;

final class PoliciesEmailConsoleClientTest extends Scope
{
    use ProjectCustom;
    use SideConsole;

    /**
     * @return \Iterator<string, array{path: string, alias: string, field: string}>
     */
    public static function provideEmailPolicies(): \Iterator
    {
        yield 'deny aliased email' => [
            'path' => '/project/policies/deny-aliased-email',
            'alias' => '/project/auth/canonical-emails',
            'field' => 'authCanonicalEmails',
        ];
        yield 'deny corporate email' => [
            'path' => '/project/policies/deny-corporate-email',
            'alias' => '/project/auth/corporate-emails',
            'field' => 'authCorporateEmails',
        ];
        yield 'deny disposable email' => [
            'path' => '/project/policies/deny-disposable-email',
            'alias' => '/project/auth/disposable-emails',
            'field' => 'authDisposableEmails',
        ];
        yield 'deny free email' => [
            'path' => '/project/policies/deny-free-email',
            'alias' => '/project/auth/free-emails',
            'field' => 'authFreeEmails',
        ];
    }

    #[DataProvider('provideEmailPolicies')]
    public function testUpdateEmailPolicy(string $path, string $alias, string $field): void
    {
        $disabled = $this->updateEmailPolicy($path, false);

        $this->assertSame(200, $disabled['headers']['status-code']);
        $this->assertFalse($disabled['body'][$field]);

        $enabled = $this->updateEmailPolicy($path, true);

        $this->assertSame(200, $enabled['headers']['status-code']);
        $this->assertTrue($enabled['body'][$field]);

        $disabled = $this->updateEmailPolicy($alias, false);

        $this->assertSame(200, $disabled['headers']['status-code']);
        $this->assertFalse($disabled['body'][$field]);
    }

    #[DataProvider('provideEmailPolicies')]
    public function testUpdateEmailPolicyRejectsInvalidEnabled(string $path, string $alias, string $field): void
    {
        $response = $this->client->call(Client::METHOD_PATCH, $path, $this->buildHeaders(), [
            'enabled' => 'not-a-boolean',
        ]);

        $this->assertSame(400, $response['headers']['status-code']);
    }

    #[DataProvider('provideEmailPolicies')]
    public function testUpdateEmailPolicyWithoutAuthentication(string $path, string $alias, string $field): void
    {
        $response = $this->updateEmailPolicy($path, true, false);

        $this->assertSame(401, $response['headers']['status-code']);
    }

    public function testUpdateAliasedEmailPolicyUsingLegacyPolicyAlias(): void
    {
        $enabled = $this->updateEmailPolicy('/project/policies/deny-canonical-email', true);

        $this->assertSame(200, $enabled['headers']['status-code']);
        $this->assertTrue($enabled['body']['authCanonicalEmails']);

        $this->updateEmailPolicy('/project/policies/deny-aliased-email', false);
    }

    private function buildHeaders(bool $authenticated = true): array
    {
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ];

        if ($authenticated) {
            $headers = \array_merge($headers, $this->getHeaders(), [
                'x-appwrite-response-format' => '1.9.4',
            ]);
        }

        return $headers;
    }

    private function updateEmailPolicy(string $path, bool $enabled, bool $authenticated = true): array
    {
        return $this->client->call(Client::METHOD_PATCH, $path, $this->buildHeaders($authenticated), [
            'enabled' => $enabled,
        ]);
    }
}
