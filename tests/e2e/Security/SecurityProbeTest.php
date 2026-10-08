<?php

declare(strict_types=1);

namespace Tests\E2E\Security;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\E2E\Security\Attack\ConsoleRole;
use Tests\E2E\Security\Attack\HeaderSpoof;
use Utopia\System\System;

/**
 * Preventative security probes: every live /v1 route × every attack class.
 * Failures are findings that are not in baseline.json (or have no reason).
 */
#[Group('security')]
final class SecurityProbeTest extends TestCase
{
    private static ?World $world = null;

    /**
     * @var list<RouteTarget>
     */
    private static array $routes = [];

    /**
     * @var list<Finding>
     */
    private static array $findings = [];

    public static function setUpBeforeClass(): void
    {
        self::$world = World::boot();
        self::$routes = Catalog::load();
    }

    public static function tearDownAfterClass(): void
    {
        if (System::getEnv('_APP_SECURITY_BASELINE') === 'update') {
            Baseline::write(Catalog::ids(self::$routes), self::$findings);
        }
        self::$world = null;
    }

    public function testCatalogEnumeratesApiRoutes(): void
    {
        $this->assertNotEmpty(self::$routes, 'Http::getRoutes() produced no /v1 routes; catalog bootstrap failed.');
        $this->assertGreaterThan(50, \count(self::$routes), 'Catalog is too small to be the live route table.');

        $ids = Catalog::ids(self::$routes);
        $this->assertSame(\count($ids), \count(\array_unique($ids)), 'Catalog contains duplicate method+path ids.');
    }

    public function testRouteInventory(): void
    {
        $baseline = Baseline::load();
        $partition = $baseline->partitionRoutes(Catalog::ids(self::$routes));

        if ($partition['fresh'] !== []) {
            $this->fail(
                \count($partition['fresh']) . " new route(s) are not in tests/e2e/Security/baseline.json.\n"
                . "They were probed automatically. Accept them with:\n"
                . "  _APP_SECURITY_BASELINE=update docker compose exec appwrite test tests/e2e/Security --group=security\n"
                . "then review the routes list.\n"
                . \implode("\n", \array_slice($partition['fresh'], 0, 30))
            );
        }

        if ($partition['stale'] !== []) {
            \fwrite(STDOUT, \count($partition['stale']) . " baseline route(s) are gone; regenerate the inventory.\n");
        }
    }

    public function testGuestAccess(): void
    {
        $this->assertAttack('guest-access');
    }

    public function testCrossTenant(): void
    {
        $this->assertAttack('cross-tenant');
    }

    public function testIdor(): void
    {
        $this->assertAttack('idor');
    }

    public function testScopeLeastPrivilege(): void
    {
        $this->assertAttack('scope-least-privilege');
    }

    public function testConsoleRole(): void
    {
        $attack = new ConsoleRole();
        $findings = $this->collect($attack);
        $findings = [...$findings, ...$attack->ownerOnly($this->world())];
        $this->recordAndAssert($findings);
    }

    public function testHeaderSpoof(): void
    {
        $attack = new HeaderSpoof();
        $findings = $this->collect($attack);
        $findings = [...$findings, ...$attack->identity($this->world())];
        $this->recordAndAssert($findings);
    }

    public function testSsrfUrl(): void
    {
        $this->assertAttack('ssrf-url');
    }

    public function testSensitiveFields(): void
    {
        $this->assertAttack('sensitive-fields');
    }

    private function assertAttack(string $name): void
    {
        $this->recordAndAssert($this->collect(Attacks::named($name)));
    }

    /**
     * @return list<Finding>
     */
    private function collect(Attack $attack): array
    {
        $world = $this->world();
        $http = $world->probe;
        $findings = [];
        $applicable = 0;

        foreach (self::$routes as $route) {
            if (! $attack->applies($route)) {
                continue;
            }
            $applicable++;
            \array_push($findings, ...$attack->probe($route, $world, $http));
        }

        \fwrite(STDOUT, $attack::getName() . ': ' . $applicable . ' routes, ' . \count($findings) . " findings\n");

        return $findings;
    }

    /**
     * @param list<Finding> $findings
     */
    private function recordAndAssert(array $findings): void
    {
        \array_push(self::$findings, ...$findings);

        $partition = Baseline::load()->partition($findings);
        foreach ($partition['stale'] as $stale) {
            \fwrite(STDOUT, 'Stale baseline entry: ' . $stale . "\n");
        }

        $lines = [];
        foreach ($partition['fresh'] as $finding) {
            $lines[] = $finding->severity . ' ' . $finding->attack . ' ' . $finding->method . ' ' . $finding->path . ' [' . $finding->probe . '] ' . $finding->detail;
        }
        foreach ($partition['unreasoned'] as $key) {
            $lines[] = 'Baseline entry has no reason: ' . \str_replace("\0", ' ', $key);
        }

        $this->assertSame([], $lines, "New security findings (or unreasoned baseline entries):\n" . \implode("\n", $lines));
    }

    private function world(): World
    {
        if (self::$world === null) {
            throw new \RuntimeException('Security world was not booted.');
        }

        return self::$world;
    }
}
