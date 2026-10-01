<?php

namespace Utopia\Tests\E2E;

use PHPUnit\Framework\TestCase;
use Utopia\Logger\Adapter;
use Utopia\Logger\Log;
use Utopia\Logger\Log\Breadcrumb;
use Utopia\Logger\Log\User;
use Utopia\Logger\Logger;

abstract class AdapterBase extends TestCase
{
    protected ?Log $log = null;

    protected ?Adapter $adapter = null;

    protected ?Adapter $invalidAdapter = null;

    protected int $expected = 200;

    protected function setUp(): void
    {
        // Prepare log
        $this->log = new Log();
        $this->log->setAction('controller.database.deleteDocument');
        $this->log->setEnvironment('production');
        $this->log->setNamespace('api');
        $this->log->setServer('digitalocean-us-001');
        $this->log->setType(Log::TYPE_ERROR);
        $this->log->setVersion('0.11.5');
        $this->log->setMessage('Document efgh5678 not found');
        $this->log->setUser(new User('efgh5678'));
        $this->log->addBreadcrumb(new Breadcrumb(Log::TYPE_DEBUG, 'http', 'DELETE /api/v1/database/abcd1234/efgh5678', \microtime(true) - 500));
        $this->log->addBreadcrumb(new Breadcrumb(Log::TYPE_DEBUG, 'auth', 'Using API key', \microtime(true) - 400));
        $this->log->addBreadcrumb(new Breadcrumb(Log::TYPE_INFO, 'auth', 'Authenticated with * Using API Key', \microtime(true) - 350));
        $this->log->addBreadcrumb(new Breadcrumb(Log::TYPE_INFO, 'database', 'Found collection abcd1234', \microtime(true) - 300));
        $this->log->addBreadcrumb(new Breadcrumb(Log::TYPE_DEBUG, 'database', 'Permission for collection abcd1234 met', \microtime(true) - 200));
        $this->log->addBreadcrumb(new Breadcrumb(Log::TYPE_ERROR, 'database', 'Missing document when searching by ID!', \microtime(true)));
        $this->log->addTag('sdk', 'Flutter');
        $this->log->addTag('sdkVersion', '0.0.1');
        $this->log->addTag('authMode', 'default');
        $this->log->addTag('authMethod', 'cookie');
        $this->log->addTag('authProvider', 'MagicLink');
        $this->log->addExtra('urgent', false);
        $this->log->addExtra('isExpected', true);
        $this->log->addExtra('file', '/User/example/server/src/server/server.js');
        $this->log->addExtra('line', '15');
    }

    /**
     * @throws \Throwable
     */
    public function testAdapter(): void
    {
        if (empty($this->log) || empty($this->adapter)) {
            throw new \Exception('Log or adapter not set');
        }
        $logger = new Logger($this->adapter);
        $response = $logger->addLog($this->log);
        $this->assertEquals($this->expected, $response);
    }

    /**
     * @throws \Throwable
     */
    public function testSampler(): void
    {
        if (empty($this->log) || empty($this->adapter)) {
            throw new \Exception('Log or adapter not set');
        }

        $logger = new Logger($this->adapter);
        $logger->setSample(0.1);

        $results = [];
        $zeroCount = 0;

        for ($x = 0; $x <= 100; $x++) {
            $result = $logger->addLog($this->log);
            $results[] = $result;
            if ($result === 0) {
                $zeroCount++;
            }
        }

        $zeroPercentage = ($zeroCount / count($results)) * 100;

        $this->assertGreaterThan(85, $zeroPercentage);
    }

    public function testAdapterFailure(): void
    {
        if (empty($this->log) || empty($this->invalidAdapter)) {
            throw new \Exception('Log or adapter not set');
        }

        $logger = new Logger($this->invalidAdapter);

        // Should return an error status code without throwing
        $statusCode = $logger->addLog($this->log);

        // Should return > 400 status code
        $this->assertGreaterThan(400, $statusCode);
    }
}
