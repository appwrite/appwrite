<?php

declare(strict_types=1);

namespace Tests\Unit\General;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Config\Config;

/**
 * Scopes are a public contract: they are stored on API keys, functions and
 * sites, and OAuth2 clients such as MCP hosts save the list they registered
 * with and keep requesting it. `app/config/scopes/lock.json` records every
 * scope that has shipped, so a removal fails here instead of in production.
 */
final class ScopesTest extends TestCase
{
    private const string LOCK = __DIR__ . '/../../../app/config/scopes/lock.json';

    /**
     * @return \Iterator<string, array{string, string}>
     */
    public static function catalogs(): \Iterator
    {
        yield 'project' => ['project', 'projectScopes'];
        yield 'organization' => ['organization', 'organizationScopes'];
        yield 'account' => ['account', 'accountScopes'];
    }

    #[DataProvider('catalogs')]
    public function testLockedScopesAreNotRemoved(string $catalog, string $param): void
    {
        $scopes = Config::getParam($param, []);

        foreach ($this->locked($catalog) as $scope) {
            $this->assertArrayHasKey(
                $scope,
                $scopes,
                "Scope '{$scope}' was removed from the {$catalog} scopes. Clients that stored it would be rejected; keep it and mark it 'deprecated' => true instead."
            );
        }
    }

    #[DataProvider('catalogs')]
    public function testScopesAreLocked(string $catalog, string $param): void
    {
        $locked = $this->locked($catalog);

        foreach (\array_keys(Config::getParam($param, [])) as $scope) {
            $this->assertContains(
                $scope,
                $locked,
                "Scope '{$scope}' is not in app/config/scopes/lock.json. Add it under '{$catalog}'; once shipped it cannot be removed."
            );
        }
    }

    /**
     * @return array<string>
     */
    private function locked(string $catalog): array
    {
        $lock = \json_decode((string) \file_get_contents(self::LOCK), true, flags: JSON_THROW_ON_ERROR);

        return $lock[$catalog] ?? [];
    }
}
