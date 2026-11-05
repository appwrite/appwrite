<?php

declare(strict_types=1);

namespace Tests\Unit\Abuse;

use Appwrite\Abuse\Limit;
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
}
