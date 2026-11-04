<?php

declare(strict_types=1);

namespace Tests\Unit\Abuse;

use Appwrite\Abuse\Limit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Http\Http;

/**
 * Limits are read only from the `abuse` label, so a route still declaring a retired label would run with no
 * limit at all.
 */
final class RoutesTest extends TestCase
{
    public function testEveryRouteDeclaresItsLimitsWithTheAbuseLabel(): void
    {
        require_once __DIR__ . '/../../../app/controllers/general.php';

        $routes = 0;
        foreach (Http::getRoutes() as $methodRoutes) {
            foreach ($methodRoutes as $route) {
                $routes++;
                foreach (['abuse-key', 'abuse-limit', 'abuse-time', 'abuse-reset'] as $retired) {
                    $this->assertNull($route->getLabel($retired, null), "{$route->getPath()} declares the retired '{$retired}' label.");
                }
                foreach ($route->getLabel('abuse', []) as $limit) {
                    $this->assertInstanceOf(Limit::class, $limit, "{$route->getPath()} declares an abuse limit that is not a Limit.");
                }
            }
        }

        $this->assertGreaterThan(100, $routes);
    }

    /**
     * @return \Iterator<string, array{string, string}>
     */
    public static function confirmations(): \Iterator
    {
        yield 'email' => ['PUT', '/v1/account/verifications/email'];
        yield 'email otp' => ['PUT', '/v1/account/verifications/email/otp'];
        yield 'phone' => ['PUT', '/v1/account/verifications/phone'];
    }

    /**
     * A verification confirmation takes the user and the secret from the request, and its limit is the only cap
     * on guesses at a six digit code. A caller trusted with throughput, a key or admin mode, must still be
     * counted here, or it can walk the whole code space and verify an address it does not own.
     */
    #[DataProvider('confirmations')]
    public function testVerificationConfirmationsAreLimitedForEveryCaller(string $method, string $path): void
    {
        require_once __DIR__ . '/../../../app/controllers/general.php';

        foreach (Http::getRoutes()[$method] ?? [] as $route) {
            if ($route->getPath() !== $path) {
                continue;
            }

            /** @var list<Limit> $limits */
            $limits = $route->getLabel('abuse', []);
            $this->assertNotEmpty($limits, "{$path} declares no abuse limit.");

            foreach ($limits as $limit) {
                $this->assertTrue($limit->everyone, "{$path} has a limit that trusted callers are exempt from.");
            }

            return;
        }

        $this->fail("{$method} {$path} is not registered.");
    }
}
