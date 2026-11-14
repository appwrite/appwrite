<?php

declare(strict_types=1);

namespace Tests\Unit\Network;

use Appwrite\Network\Platform;
use PHPUnit\Framework\TestCase;

final class ForceHttpsTest extends TestCase
{
    public function testDefaultJobsHostAllowedOverHttp(): void
    {
        $previous = \getenv('_APP_JOBS_ENDPOINT');
        \putenv('_APP_JOBS_ENDPOINT');

        try {
            $localHosts = Platform::getLocalHosts(port: '80');

            $this->assertContains('localhost', $localHosts);
            $this->assertContains('appwrite', $localHosts);
            $this->assertContains('appwrite:80', $localHosts);

            // Orchestrator callback to default internal appwrite host
            $this->assertTrue(Platform::isForceHttpsExempt('http', 'appwrite', '/v1/jobs/event', $localHosts));
            $this->assertTrue(Platform::isForceHttpsExempt('http', 'appwrite:80', '/v1/jobs/event', $localHosts));
        } finally {
            $previous === false ? \putenv('_APP_JOBS_ENDPOINT') : \putenv('_APP_JOBS_ENDPOINT=' . $previous);
        }
    }

    public function testCustomJobsHostAndPortAllowedOverHttp(): void
    {
        $localHosts = Platform::getLocalHosts(port: '80', jobsEndpoint: 'http://internal-api:8080');

        $this->assertContains('internal-api', $localHosts);
        $this->assertContains('internal-api:8080', $localHosts);
        $this->assertTrue(Platform::isForceHttpsExempt('http', 'internal-api:8080', '/v1/jobs/event', $localHosts));
    }

    public function testMigrationHostAllowedOverHttp(): void
    {
        $localHosts = Platform::getLocalHosts(port: '80', migrationHost: 'migration.internal');

        $this->assertContains('migration.internal', $localHosts);
        $this->assertContains('migration.internal:80', $localHosts);
        $this->assertTrue(Platform::isForceHttpsExempt('http', 'migration.internal', '/v1/databases', $localHosts));
    }

    public function testJobsEventCallbackExemptRegardlessOfHost(): void
    {
        $localHosts = Platform::getLocalHosts(port: '80');

        // Internal Docker IP host calling jobs/event callback is exempt
        $this->assertTrue(Platform::isForceHttpsExempt('http', '172.18.0.2', '/v1/jobs/event?project=proj1', $localHosts));

        // Public API calls to regular endpoints over HTTP are NOT exempt
        $this->assertFalse(Platform::isForceHttpsExempt('http', 'example.com', '/v1/account', $localHosts));
        $this->assertFalse(Platform::isForceHttpsExempt('http', 'example.com', '/v1/databases', $localHosts));

        // HTTPS calls are always exempt
        $this->assertTrue(Platform::isForceHttpsExempt('https', 'example.com', '/v1/account', $localHosts));
    }
}
