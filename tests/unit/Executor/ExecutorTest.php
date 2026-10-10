<?php

declare(strict_types=1);

namespace Tests\Unit\Executor;

use Executor\Executor;
use PHPUnit\Framework\TestCase;
use Utopia\Client\Tests\Server\Http;

final class ExecutorTest extends TestCase
{
    public function testReusesConnectionsAcrossCalls(): void
    {
        $previous = getenv('_APP_EXECUTOR_HOST');

        try {
            $connections = Http::dropsFirstKeepAliveConnection(function (int $port): void {
                putenv('_APP_EXECUTOR_HOST=http://127.0.0.1:' . $port);
                $executor = new Executor();

                for ($i = 0; $i < 4; $i++) {
                    $executor->deleteRuntime('project', 'deployment');
                }
            });
        } finally {
            putenv($previous === false ? '_APP_EXECUTOR_HOST' : '_APP_EXECUTOR_HOST=' . $previous);
        }

        // The server closes the first connection after one response; the other calls share the second.
        $this->assertSame(2, $connections);
    }
}
