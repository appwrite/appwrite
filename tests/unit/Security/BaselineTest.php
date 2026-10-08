<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Tests\E2E\Security\Baseline;
use Tests\E2E\Security\Finding;

final class BaselineTest extends TestCase
{
    public function testPartitionSeparatesFreshKnownAndStale(): void
    {
        $path = \sys_get_temp_dir() . '/security-baseline-' . \bin2hex(\random_bytes(4)) . '.json';
        try {
            Baseline::write(['GET /v1/locale'], [
                new Finding('guest-access', 'GET', '/v1/users', 'guest', 'open', Finding::ERROR),
            ], $path);

            $document = \json_decode((string) \file_get_contents($path), true);
            $document['findings'][0]['reason'] = 'documented exception';
            \file_put_contents($path, \json_encode($document, JSON_PRETTY_PRINT) . "\n");

            $baseline = Baseline::load($path);
            $fresh = new Finding('ssrf-url', 'GET', '/v1/avatars/image', 'url:127.0.0.1', 'accepted');
            $known = new Finding('guest-access', 'GET', '/v1/users', 'guest', 'open');
            $partition = $baseline->partition([$fresh, $known]);

            $this->assertSame(['ssrf-url'], \array_map(static fn (Finding $finding): string => $finding->attack, $partition['fresh']));
            $this->assertSame(['guest-access'], \array_map(static fn (Finding $finding): string => $finding->attack, $partition['known']));
            $this->assertSame([], $partition['unreasoned']);
            $this->assertSame([], $partition['stale']);

            $routes = $baseline->partitionRoutes(['GET /v1/locale', 'POST /v1/account']);
            $this->assertSame(['POST /v1/account'], $routes['fresh']);
            $this->assertSame([], $routes['stale']);
        } finally {
            if (\is_file($path)) {
                \unlink($path);
            }
        }
    }

    public function testEmptyReasonIsUnreasoned(): void
    {
        $path = \sys_get_temp_dir() . '/security-baseline-' . \bin2hex(\random_bytes(4)) . '.json';
        try {
            Baseline::write([], [
                new Finding('guest-access', 'GET', '/v1/users', 'guest', 'open'),
            ], $path);

            $baseline = Baseline::load($path);
            $partition = $baseline->partition([
                new Finding('guest-access', 'GET', '/v1/users', 'guest', 'open'),
            ]);

            $this->assertNotSame([], $partition['unreasoned']);
        } finally {
            if (\is_file($path)) {
                \unlink($path);
            }
        }
    }

    public function testWriteKeepsFindingsTheRunDidNotEmit(): void
    {
        $path = \sys_get_temp_dir() . '/security-baseline-' . \bin2hex(\random_bytes(4)) . '.json';
        try {
            Baseline::write(['GET /v1/users'], [
                new Finding('guest-access', 'GET', '/v1/users', 'guest', 'open'),
                new Finding('ssrf-url', 'GET', '/v1/avatars/image', 'url:127.0.0.1', 'accepted'),
            ], $path);

            $document = \json_decode((string) \file_get_contents($path), true);
            $document['findings'][0]['reason'] = 'guest listing is public';
            $document['findings'][1]['reason'] = 'stored url only';
            \file_put_contents($path, \json_encode($document, JSON_PRETTY_PRINT) . "\n");

            Baseline::write(['GET /v1/users'], [
                new Finding('ssrf-url', 'GET', '/v1/avatars/image', 'url:127.0.0.1', 'accepted again'),
            ], $path);

            $rows = Baseline::load($path)->rawFindings();
            $reasons = [];
            foreach ($rows as $row) {
                $reasons[$row['attack']] = $row['reason'];
            }

            $this->assertSame('guest listing is public', $reasons['guest-access']);
            $this->assertSame('stored url only', $reasons['ssrf-url']);
        } finally {
            if (\is_file($path)) {
                \unlink($path);
            }
        }
    }

    public function testEmptyRouteInventoryDoesNotLock(): void
    {
        $baseline = new Baseline(['version' => 1, 'routes' => [], 'findings' => []]);
        $partition = $baseline->partitionRoutes(['GET /v1/ping']);

        $this->assertSame([], $partition['fresh']);
        $this->assertSame([], $partition['stale']);
    }

    public function testLockedInventoryRejectsUnreviewedRoute(): void
    {
        $baseline = Baseline::load();
        $listed = $baseline->routes();

        $this->assertNotSame([], $listed, 'Route inventory must be locked so new endpoints fail CI.');

        $canary = 'POST /v1/security-inventory-canary';
        $this->assertNotContains($canary, $listed, 'Canary must not be pre-listed.');

        $partition = $baseline->partitionRoutes([...$listed, $canary]);

        $this->assertSame([$canary], $partition['fresh']);
        $this->assertSame([], $partition['stale']);
    }
}
