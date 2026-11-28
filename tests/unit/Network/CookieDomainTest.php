<?php

declare(strict_types=1);

namespace Tests\Unit\Network;

use Appwrite\Network\Platform;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;

final class CookieDomainTest extends TestCase
{
    public function testLocalHostsAndIpAddressesReturnHostOnlyCookieDomain(): void
    {
        $project = $this->project('proj-1', '100', [
            ['type' => Platform::TYPE_WEB, 'hostname' => 'example.com'],
        ]);
        $localHosts = ['localhost', 'localhost:8080', 'migration.local', 'migration.local:8080'];

        $this->assertNull(Platform::getCookieDomain('localhost', $project, $localHosts));
        $this->assertNull(Platform::getCookieDomain('localhost:8080', $project, $localHosts));
        $this->assertNull(Platform::getCookieDomain('migration.local', $project, $localHosts));
        $this->assertNull(Platform::getCookieDomain('127.0.0.1', $project, $localHosts));
        $this->assertNull(Platform::getCookieDomain('::1', $project, $localHosts));
        $this->assertNull(Platform::getCookieDomain('[::1]', $project, $localHosts));
        $this->assertNull(Platform::getCookieDomain('', $project, $localHosts));
    }

    public function testConsoleProjectRespectsRootSessionFlag(): void
    {
        $console = new Document(['$id' => 'console', '$sequence' => '1']);

        $this->assertSame(
            '.console.example.com',
            Platform::getCookieDomain('console.example.com', $console, consoleRootSession: false),
        );
        $this->assertSame(
            '.example.com',
            Platform::getCookieDomain('console.example.com', $console, consoleRootSession: true),
        );
        $this->assertSame(
            '.console.internal',
            Platform::getCookieDomain('console.internal', $console, consoleRootSession: true),
        );
    }

    public function testCustomApiDomainUsesParentWebPlatformDomainWhenVerified(): void
    {
        $project = $this->project('proj-1', '42', [
            ['type' => Platform::TYPE_WEB, 'hostname' => 'Example.COM'],
        ]);
        $rule = $this->rule('cloud.example.com', '42');

        $this->assertSame(
            '.example.com',
            Platform::getCookieDomain(
                hostname: 'cloud.example.com',
                project: $project,
                localHosts: ['localhost', 'localhost:80'],
                platformHostnames: ['cloud.appwrite.io', 'console.appwrite.io'],
                hostRule: $rule,
            ),
        );
    }

    public function testMultiLevelSubdomainSelectsBroadestValidParentWebPlatform(): void
    {
        $subOnly = $this->project('proj-1', '42', [
            ['type' => Platform::TYPE_WEB, 'hostname' => 'app.example.com'],
        ]);
        $both = $this->project('proj-1', '42', [
            ['type' => Platform::TYPE_WEB, 'hostname' => 'app.example.com'],
            ['type' => Platform::TYPE_WEB, 'hostname' => 'example.com'],
        ]);
        $legacyWeb = $this->project('proj-1', '42', [
            ['type' => 'flutter-web', 'hostname' => 'example.com'],
        ]);
        $rule = $this->rule('api.app.example.com', '42');

        $this->assertSame(
            '.app.example.com',
            Platform::getCookieDomain('api.app.example.com', $subOnly, platformHostnames: ['cloud.appwrite.io'], hostRule: $rule),
        );
        $this->assertSame(
            '.example.com',
            Platform::getCookieDomain('api.app.example.com', $both, platformHostnames: ['cloud.appwrite.io'], hostRule: $rule),
        );
        $this->assertSame(
            '.example.com',
            Platform::getCookieDomain('api.app.example.com', $legacyWeb, platformHostnames: ['cloud.appwrite.io'], hostRule: $rule),
        );
    }

    public function testPublicSuffixAndPrivateRegistryBoundariesAreEnforced(): void
    {
        $coUkProject = $this->project('proj-1', '42', [
            ['type' => Platform::TYPE_WEB, 'hostname' => 'co.uk'],
            ['type' => Platform::TYPE_WEB, 'hostname' => 'example.co.uk'],
        ]);
        $publicSuffixOnly = $this->project('proj-2', '43', [
            ['type' => Platform::TYPE_WEB, 'hostname' => 'co.uk'],
        ]);
        $githubIoValid = $this->project('proj-3', '44', [
            ['type' => Platform::TYPE_WEB, 'hostname' => 'github.io'],
            ['type' => Platform::TYPE_WEB, 'hostname' => 'app.github.io'],
        ]);
        $githubIoPublicOnly = $this->project('proj-4', '45', [
            ['type' => Platform::TYPE_WEB, 'hostname' => 'github.io'],
        ]);

        $this->assertSame(
            '.example.co.uk',
            Platform::getCookieDomain('api.sub.example.co.uk', $coUkProject, hostRule: $this->rule('api.sub.example.co.uk', '42')),
        );
        $this->assertSame(
            '.api.sub.example.co.uk',
            Platform::getCookieDomain('api.sub.example.co.uk', $publicSuffixOnly, hostRule: $this->rule('api.sub.example.co.uk', '43')),
        );
        $this->assertSame(
            '.app.github.io',
            Platform::getCookieDomain('api.app.github.io', $githubIoValid, hostRule: $this->rule('api.app.github.io', '44')),
        );
        $this->assertSame(
            '.api.app.github.io',
            Platform::getCookieDomain('api.app.github.io', $githubIoPublicOnly, hostRule: $this->rule('api.app.github.io', '45')),
        );
    }

    public function testIgnoresNonWebWildcardAndSiblingHostnames(): void
    {
        $project = $this->project('proj-1', '42', [
            ['type' => Platform::TYPE_APPLE, 'key' => 'example.com'],
            ['type' => Platform::TYPE_WEB, 'hostname' => '*.example.com'],
            ['type' => Platform::TYPE_WEB, 'hostname' => 'app.example.com'],
            ['type' => Platform::TYPE_WEB, 'hostname' => 'notexample.com'],
        ]);
        $rule = $this->rule('cloud.example.com', '42');

        $this->assertNull(Platform::findParentWebDomain('cloud.example.com', $project));
        $this->assertSame(
            '.cloud.example.com',
            Platform::getCookieDomain('cloud.example.com', $project, hostRule: $rule),
        );
    }

    public function testBlocksSharedPlatformHostsEvenWithMatchingPlatformAndRule(): void
    {
        $project = $this->project('proj-1', '42', [
            ['type' => Platform::TYPE_WEB, 'hostname' => 'appwrite.io'],
            ['type' => Platform::TYPE_WEB, 'hostname' => 'example.com'],
        ]);
        $platformHosts = ['cloud.appwrite.io', 'console.appwrite.io', 'localhost'];

        $this->assertNull(Platform::findParentWebDomain('fra.cloud.appwrite.io', $project, ['localhost'], $platformHosts));
        $this->assertSame(
            '.fra.cloud.appwrite.io',
            Platform::getCookieDomain(
                hostname: 'fra.cloud.appwrite.io',
                project: $project,
                localHosts: ['localhost'],
                platformHostnames: $platformHosts,
                hostRule: $this->rule('fra.cloud.appwrite.io', '42'),
            ),
        );

        // Localhost in platformHostnames must not block external custom domains
        $this->assertSame(
            '.example.com',
            Platform::getCookieDomain(
                hostname: 'cloud.example.com',
                project: $project,
                localHosts: ['localhost'],
                platformHostnames: ['localhost'],
                hostRule: $this->rule('cloud.example.com', '42'),
            ),
        );
    }

    public function testRequiresVerifiedOwnedApiRuleBeforeWideningCookieDomain(): void
    {
        $project = $this->project('proj-1', '42', [
            ['type' => Platform::TYPE_WEB, 'hostname' => 'example.com'],
        ]);
        $projectWithoutSequence = $this->project('proj-1', '', [
            ['type' => Platform::TYPE_WEB, 'hostname' => 'example.com'],
        ]);

        // Missing or empty rule
        $this->assertSame('.cloud.example.com', Platform::getCookieDomain('cloud.example.com', $project));
        $this->assertSame('.cloud.example.com', Platform::getCookieDomain('cloud.example.com', $project, hostRule: new Document()));

        // Cross-tenant rule owned by another project
        $this->assertSame('.cloud.example.com', Platform::getCookieDomain('cloud.example.com', $project, hostRule: $this->rule('cloud.example.com', '999')));

        // Wrong project sequence missing
        $this->assertSame('.cloud.example.com', Platform::getCookieDomain('cloud.example.com', $projectWithoutSequence, hostRule: $this->rule('cloud.example.com', '')));

        // Non-API rule (e.g. site/function)
        $this->assertSame('.cloud.example.com', Platform::getCookieDomain('cloud.example.com', $project, hostRule: $this->rule('cloud.example.com', '42', type: 'site')));

        // Unverified / pending DNS rule statuses
        $this->assertSame('.cloud.example.com', Platform::getCookieDomain('cloud.example.com', $project, hostRule: $this->rule('cloud.example.com', '42', status: 'created')));
        $this->assertSame('.cloud.example.com', Platform::getCookieDomain('cloud.example.com', $project, hostRule: $this->rule('cloud.example.com', '42', status: 'verifying')));
        $this->assertSame('.cloud.example.com', Platform::getCookieDomain('cloud.example.com', $project, hostRule: $this->rule('cloud.example.com', '42', status: 'unverified')));

        // Domain mismatch on rule
        $this->assertSame('.cloud.example.com', Platform::getCookieDomain('cloud.example.com', $project, hostRule: $this->rule('other.example.com', '42')));
    }

    /**
     * @param array<int, array<string, mixed>> $platforms
     */
    private function project(string $id, string $sequence, array $platforms): Document
    {
        $data = [
            '$id' => $id,
            'platforms' => $platforms,
        ];
        if ($sequence !== '') {
            $data['$sequence'] = $sequence;
        }

        return new Document($data);
    }

    private function rule(string $domain, string $projectSequence, string $type = 'api', string $status = 'verified'): Document
    {
        return new Document([
            '$id' => 'rule-1',
            'domain' => $domain,
            'projectInternalId' => $projectSequence,
            'type' => $type,
            'status' => $status,
        ]);
    }
}
