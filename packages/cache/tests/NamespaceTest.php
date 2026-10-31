<?php

declare(strict_types=1);

namespace Utopia\Cache\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\Memory;
use Utopia\Cache\Cache;

final class NamespaceTest extends TestCase
{
    public function testSharesExistingValuesWithoutChangingTheOriginalView(): void
    {
        $backing = new Cache(new Memory());
        $cache = $backing->withNamespace('v2:');
        $backing->save('entry', 'unprefixed');
        $backing->save('v2:entry', 'warm');

        $this->assertSame('warm', $cache->load('entry', 60));
        $cache->save('entry', 'updated');
        $this->assertSame('updated', $backing->load('v2:entry', 60));
        $this->assertSame('unprefixed', $backing->load('entry', 60));
    }

    public function testSharesSnapshotsAndRejectsFillsInvalidatedThroughTheOtherView(): void
    {
        $backing = new Cache(new Memory());
        $cache = $backing->withNamespace('v2:');
        $snapshot = $backing->loadVersioned('v2:state');
        $this->assertSame($snapshot, $cache->loadVersioned('state'));
        $version = $cache->compareAndSwap('state', ['owners' => []], $snapshot['version']);
        $this->assertIsString($version);
        $this->assertSame(['version' => $version, 'value' => ['owners' => []]], $backing->loadVersioned('v2:state'));

        $lease = $cache->getLease('entry');
        $this->assertSame($lease, $backing->getLease('v2:entry'));
        $cache->saveWithLease('entry', 'original', '', $lease);
        $backing->touch('v2:entry');
        $this->assertFalse($cache->saveWithLease('entry', 'stale', '', $lease));
        $this->assertSame('original', $cache->load('entry', 60));
        $cache->touch('entry');
        $this->assertNotSame($lease, $backing->getLease('v2:entry'));
        $backing->purge('v2:state');
        $this->assertFalse($cache->compareAndSwap('state', ['owners' => ['stale' => 1]], $version));
    }

    public function testRecordedPurgesReplayLogicalKeysExactlyOnce(): void
    {
        $backing = new Cache(new Memory());
        $cache = $backing->withNamespace('v2:');
        $otherRegion = (new Cache(new Memory()))->withNamespace('v2:');
        $cache->save('users', 'local', 'list');
        $otherRegion->save('users', 'remote', 'list');

        $purges = $cache->recordPurges(fn (): bool => $cache->purge('Users', 'List'));
        foreach ($purges as $key => $fields) {
            foreach ($fields as $field => $_) {
                $otherRegion->purge($key, $field);
            }
        }

        $this->assertSame(['users' => ['list' => true]], $purges);
        $this->assertFalse($backing->load('v2:users', 60, 'list'));
        $this->assertFalse($otherRegion->load('users', 60, 'list'));
    }

    public function testPreservesCaseSensitivityAndKeepsNamespaceViewsIndependent(): void
    {
        $backing = new Cache(new Memory());
        $backing->setCaseSensitivity(true);
        $cache = $backing->withNamespace('V2:');
        $other = $cache->withNamespace('Other:');
        $cache->save('Key', 'first', 'Field');
        $other->save('Key', 'second', 'Field');

        $this->assertSame('first', $backing->load('V2:Key', 60, 'Field'));
        $this->assertSame('second', $backing->load('Other:Key', 60, 'Field'));
        $this->assertFalse($cache->load('key', 60, 'Field'));
        $this->assertFalse($backing->load('Key', 60, 'Field'));
    }
}
