<?php

declare(strict_types=1);

namespace Tests\Unit\Executor;

use Executor\Exception;
use Executor\Executor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExceptionTest extends TestCase
{
    /**
     * @param array<string, mixed>|string $body
     */
    #[DataProvider('responseProvider')]
    public function testResponseIsClassifiedOnlyWhenTheExecutorNamedTheFailure(int $status, array|string $body, bool $classified, string $message): void
    {
        $error = Exception::fromResponse($status, $body);

        $this->assertSame($classified, $error->isClassified());
        $this->assertSame($message, $error->getMessage());
        $this->assertSame($status, $error->getCode());
    }

    public function testUnreachableExecutorIsNotClassified(): void
    {
        $host = \getenv('_APP_EXECUTOR_HOST');
        // Nothing listens on the discard port, so the connection is refused.
        \putenv('_APP_EXECUTOR_HOST=http://127.0.0.1:9/v1');

        try {
            (new Executor())->createExecution(
                projectId: 'project',
                deploymentId: 'deployment',
                body: null,
                variables: [],
                timeout: 1,
                image: 'openruntimes/node:v5-22',
                source: '/storage/functions/code.tar.gz',
                entrypoint: 'index.js',
                version: 'v5',
                path: '/',
                method: 'POST',
                headers: [],
                cpus: 1,
                memory: 512,
                logging: true,
                requestTimeout: 1,
            );
            $this->fail('An unreachable executor must fail the execution');
        } catch (Exception $error) {
            $this->assertFalse($error->isClassified());
            $this->assertSame(0, $error->getCode());
        } finally {
            \putenv($host === false ? '_APP_EXECUTOR_HOST' : "_APP_EXECUTOR_HOST={$host}");
        }
    }

    /**
     * Bodies as the executor, edge and a gateway in front of them answer.
     *
     * @return \Iterator<string, array{int, array<string, mixed>|string, bool, string}>
     */
    public static function responseProvider(): \Iterator
    {
        yield 'runtime crashed' => [
            502,
            ['type' => 'router_runtime_crashed', 'message' => 'The runtime crashed while starting. Check the deployment logs and redeploy.', 'code' => 502],
            true,
            'The runtime crashed while starting. Check the deployment logs and redeploy.',
        ];
        yield 'execution timed out' => [
            400,
            ['type' => 'execution_timeout', 'message' => 'Timed out waiting for execution.', 'code' => 400],
            true,
            'Timed out waiting for execution.',
        ];
        yield 'executor internal error' => [
            500,
            ['type' => 'general_unknown', 'message' => 'Internal server error.', 'code' => 500],
            false,
            'Internal server error.',
        ];
        yield 'gateway page' => [502, 'Bad Gateway', false, 'Bad Gateway'];
    }
}
