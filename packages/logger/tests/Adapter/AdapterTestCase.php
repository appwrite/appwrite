<?php

declare(strict_types=1);

namespace Utopia\Logger\Tests\Adapter;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Utopia\Logger\Log;
use Utopia\Logger\Log\Breadcrumb;
use Utopia\Logger\Log\User;

/**
 * Builds the log every adapter test pushes through a recording client, and
 * captures what the adapter writes to the PHP error log.
 */
abstract class AdapterTestCase extends TestCase
{
    protected Client $client;

    private string $errors = '';

    protected function setUp(): void
    {
        $this->client = new Client();
    }

    protected function tearDown(): void
    {
        if ($this->errors !== '') {
            @unlink($this->errors);
        }
    }

    /**
     * Send error_log() output to a file errors() reads. PHPUnit points
     * error_log at its own capture between setUp() and the test method,
     * so this is called from the test body; PHPUnit restores the target.
     */
    protected function captureErrors(): void
    {
        $this->errors = tempnam(sys_get_temp_dir(), 'logger') ?: throw new RuntimeException('Cannot create a temporary file.');
        ini_set('error_log', $this->errors);
    }

    protected function log(): Log
    {
        $log = new Log();
        $log->setTimestamp(1700000000.25);
        $log->setAction('controller.database.deleteDocument');
        $log->setEnvironment(Log::ENVIRONMENT_PRODUCTION);
        $log->setNamespace('api');
        $log->setServer('digitalocean-us-001');
        $log->setType(Log::TYPE_ERROR);
        $log->setVersion('0.11.5');
        $log->setMessage('Document efgh5678 not found');
        $log->setUser(new User('efgh5678', 'user@example.com', 'name'));
        $log->addBreadcrumb(new Breadcrumb(Log::TYPE_DEBUG, 'http', 'DELETE /v1/documents/efgh5678', 1699999999.5));
        $log->addTag('sdk', 'Flutter');
        $log->addExtra('file', '/server/src/server.js');
        $log->addExtra('line', '15');

        return $log;
    }

    /**
     * @return array<mixed>
     */
    protected function json(RequestInterface $request): array
    {
        $decoded = json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    protected function errors(): string
    {
        return (string) file_get_contents($this->errors);
    }
}
