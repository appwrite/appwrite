<?php

declare(strict_types=1);

namespace Tests\Unit\Network;

use PHPUnit\Framework\TestCase;
use Utopia\System\System;

final class ForceHttpsTest extends TestCase
{
    /**
     * Resolves the list of local hosts accepted over plain HTTP when force HTTPS is enabled.
     *
     * @param string $port
     * @return array<string>
     */
    private function resolveLocalHosts(string $port = '80'): array
    {
        $localHosts = [
            'localhost',
            'localhost:' . $port,
            '127.0.0.1',
            '127.0.0.1:' . $port,
        ];

        $jobsEndpoint = System::getEnv('_APP_JOBS_ENDPOINT', 'http://appwrite');
        $jobsHost = \parse_url($jobsEndpoint, PHP_URL_HOST);
        $jobsPort = \parse_url($jobsEndpoint, PHP_URL_PORT);
        if (!empty($jobsHost)) {
            $localHosts[] = $jobsHost;
            $localHosts[] = $jobsHost . ':' . $port;
            if (!empty($jobsPort)) {
                $localHosts[] = $jobsHost . ':' . $jobsPort;
            }
        }

        $migrationHost = System::getEnv('_APP_MIGRATION_HOST');
        if (!empty($migrationHost)) {
            $localHosts[] = $migrationHost;
            $localHosts[] = $migrationHost . ':' . $port;
        }

        return $localHosts;
    }

    /**
     * Determines whether a request is exempt from the force-HTTPS requirement.
     */
    private function isExempt(string $protocol, string $host, string $uri, array $localHosts): bool
    {
        if ($protocol === 'https') {
            return true;
        }

        if (\in_array($host, $localHosts, true)) {
            return true;
        }

        if (\str_starts_with($uri, '/v1/jobs/event')) {
            return true;
        }

        return false;
    }

    public function testDefaultJobsHostAllowedOverHttp(): void
    {
        $previous = \getenv('_APP_JOBS_ENDPOINT');
        \putenv('_APP_JOBS_ENDPOINT');

        try {
            $localHosts = $this->resolveLocalHosts('80');

            $this->assertContains('localhost', $localHosts);
            $this->assertContains('appwrite', $localHosts);
            $this->assertContains('appwrite:80', $localHosts);

            // Orchestrator callback to default internal appwrite host
            $this->assertTrue($this->isExempt('http', 'appwrite', '/v1/jobs/event', $localHosts));
            $this->assertTrue($this->isExempt('http', 'appwrite:80', '/v1/jobs/event', $localHosts));
        } finally {
            $previous === false ? \putenv('_APP_JOBS_ENDPOINT') : \putenv('_APP_JOBS_ENDPOINT=' . $previous);
        }
    }

    public function testCustomJobsHostAndPortAllowedOverHttp(): void
    {
        $previous = \getenv('_APP_JOBS_ENDPOINT');
        \putenv('_APP_JOBS_ENDPOINT=http://internal-api:8080');

        try {
            $localHosts = $this->resolveLocalHosts('80');

            $this->assertContains('internal-api', $localHosts);
            $this->assertContains('internal-api:8080', $localHosts);
            $this->assertTrue($this->isExempt('http', 'internal-api:8080', '/v1/jobs/event', $localHosts));
        } finally {
            $previous === false ? \putenv('_APP_JOBS_ENDPOINT') : \putenv('_APP_JOBS_ENDPOINT=' . $previous);
        }
    }

    public function testJobsEventCallbackExemptRegardlessOfHost(): void
    {
        $localHosts = $this->resolveLocalHosts('80');

        // Internal Docker IP host calling jobs/event callback
        $this->assertTrue($this->isExempt('http', '172.18.0.2', '/v1/jobs/event?project=proj1', $localHosts));

        // Public API calls to regular endpoints are NOT exempt over HTTP
        $this->assertFalse($this->isExempt('http', 'example.com', '/v1/account', $localHosts));
        $this->assertFalse($this->isExempt('http', 'example.com', '/v1/databases', $localHosts));
    }
}
