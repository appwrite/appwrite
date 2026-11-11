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

    /**
     * A route that takes a `secret` or an `otp` from the request and verifies it against a proof is checking a
     * value only the owner of an inbox, a handset or an invite should hold, and the code proof's secret is six
     * digits by default. Nothing records a failed attempt on the token, so the route's abuse limits are the only
     * cap on guesses. Every limit kept per a part the caller chooses -- the account or the challenge it names --
     * must therefore count for every caller: being trusted with throughput, as a key or admin mode is, is not
     * being trusted with the inbox the code was sent to. A limit kept only per caller, per ip, stays exempt, or
     * one server relaying the logins of many users would lock all of them out at once.
     */
    public function testRoutesThatVerifyASecretAreLimitedForEveryCaller(): void
    {
        require_once __DIR__ . '/../../../app/controllers/general.php';

        $verifying = [];
        foreach (Http::getRoutes() as $method => $methodRoutes) {
            foreach ($methodRoutes as $route) {
                $params = $route->getParams();
                $injections = $route->getInjections();
                $proven = \array_key_exists('secret', $params) && (\array_key_exists('proofForCode', $injections) || \array_key_exists('proofForToken', $injections));

                if (! $proven && ! \array_key_exists('otp', $params)) {
                    continue;
                }

                $path = $method . ' ' . $route->getPath();
                $verifying[] = $path;

                /** @var list<Limit> $limits */
                $limits = $route->getLabel('abuse', []);
                if ($proven) {
                    $this->assertNotEmpty($limits, "{$path} verifies a secret against a proof but declares no abuse limit.");
                }

                foreach ($limits as $limit) {
                    $chosen = \array_values(\array_filter($limit->by, fn (string $part): bool => \str_starts_with($part, 'param.')));
                    if ($chosen === []) {
                        continue;
                    }

                    $this->assertTrue($limit->everyone, "{$path} verifies a secret and is limited per '" . \implode("', '", $chosen) . "', but trusted callers are exempt from that limit.");
                }
            }
        }

        // The set is derived, so a route registered later is locked the moment it is added. These only catch the
        // detector going blind, by a renamed proof injection or secret param.
        $this->assertContains('PUT /v1/account/recovery/otp', $verifying);
        $this->assertContains('POST /v1/account/sessions/token', $verifying);
        $this->assertContains('PUT /v1/account/verifications/email/otp', $verifying);
        $this->assertContains('PUT /v1/account/mfa/challenges', $verifying);
    }
}
