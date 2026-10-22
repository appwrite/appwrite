<?php

declare(strict_types=1);

namespace Tests\Unit\General;

use Appwrite\Docker\Compose;
use PHPUnit\Framework\TestCase;

/**
 * Locks the isolation the shipped stack gives customer code. Builds and
 * runtimes execute untrusted code; what they can reach, and who may drive
 * the services that create their containers, must not loosen by accident.
 */
final class ComposeIsolationTest extends TestCase
{
    private Compose $compose;

    protected function setUp(): void
    {
        $data = \file_get_contents(__DIR__ . '/../../../docker-compose.yml');
        $this->assertNotFalse($data, 'Failed to read the compose file');

        $this->compose = new Compose($data);
    }

    public function testJobsApiRequiresTheKeyAppwritePresents(): void
    {
        $orchestrator = $this->compose->getService('orchestrator');

        $keyFile = $orchestrator->getEnvironment()->getVar('API_KEY_FILE');
        $this->assertNotSame('', $keyFile, 'Without API_KEY_FILE the jobs API accepts requests from anyone who can reach it');

        $this->assertStringStartsWith('/run/secrets/', $keyFile);
        $secret = \substr($keyFile, \strlen('/run/secrets/'));
        $this->assertContains($secret, $orchestrator->getSecrets());

        $secrets = $this->compose->getSecrets();
        $this->assertArrayHasKey($secret, $secrets);
        $this->assertSame('_APP_JOBS_SECRET', $secrets[$secret]['environment'] ?? '', 'The jobs API key must be the secret the jobs resource authenticates with');
    }

    public function testBuildsRunOffTheControlPlaneNetwork(): void
    {
        $orchestrator = $this->compose->getService('orchestrator');

        foreach (['ORCHESTRATOR_NETWORK', 'DOCKER_NETWORK'] as $name) {
            $network = $orchestrator->getEnvironment()->getVar($name);
            $this->assertSame('builds', $network, "{$name} places build containers on the network with the databases, Redis and the executor");
        }

        $this->assertContains('builds', $this->compose->getNetworks());
        $this->assertContains('builds', $orchestrator->getNetworks(), 'The sidecar reaches the artifact endpoint over the builds network');

        $members = [];
        foreach ($this->compose->getServices() as $name => $service) {
            if (\in_array('builds', $service->getNetworks(), true)) {
                $members[] = $name;
            }
        }

        \sort($members);
        $this->assertSame(['appwrite', 'orchestrator'], $members, 'Only the API (presigned sources, callbacks) and the orchestrator belong on the builds network');
    }

    public function testNoStackServiceJoinsTheRuntimesNetwork(): void
    {
        // The executor attaches itself at boot; from the stack, nothing else
        // may sit next to the runtime containers.
        foreach ($this->compose->getServices() as $name => $service) {
            $this->assertNotContains('runtimes', $service->getNetworks(), "{$name} must not join the runtimes network");
        }
    }
}
