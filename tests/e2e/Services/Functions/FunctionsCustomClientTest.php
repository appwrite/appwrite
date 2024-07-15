<?php

namespace Tests\E2E\Services\Functions;

use CURLFile;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Role;

class FunctionsCustomClientTest extends Scope
{
    use FunctionsBase;
    use ProjectCustom;
    use SideClient;

    public function testBenchmark(): void
    {
        $coldTimes = [];
        $warmTimes = [];

        $function = $this->client->call(Client::METHOD_POST, '/functions', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'functionId' => ID::unique(),
            'name' => 'Test',
            'execute' => [Role::any()->toString()],
            'runtime' => 'php-8.0',
            'entrypoint' => 'index.php',
            'timeout' => 10,
        ]);
        $functionId = $function['body']['$id'] ?? '';
        $this->assertEquals(201, $function['headers']['status-code'], json_encode($function, JSON_PRETTY_PRINT));

        $code = realpath(__DIR__ . '/../../../resources/functions') . "/php/code.tar.gz";
        $this->packageCode('php');

        for ($i = 0; $i < 20; $i++) {
            $deployment = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/deployments', [
                'content-type' => 'multipart/form-data',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ], [
                'entrypoint' => 'index.php',
                'code' => new CURLFile($code, 'application/x-gzip', \basename($code)),
                'activate' => true
            ]);
            $deploymentId = $deployment['body']['$id'] ?? '';

            while (true) {
                $deployment = $this->client->call(Client::METHOD_GET, '/functions/' . $functionId . '/deployments/' . $deploymentId, [
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id'],
                    'x-appwrite-key' => $this->getProject()['apiKey'],
                ]);

                if (
                    $deployment['headers']['status-code'] >= 400
                    || \in_array($deployment['body']['status'], ['ready', 'failed'])
                ) {
                    break;
                }

                \sleep(1);
            }

            $this->assertEquals('ready', $deployment['body']['status']);

            $start = \microtime(true);
            $execution = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/executions', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()), [
                'async' => false,
            ]);
            $end = \microtime(true);

            $this->assertEquals(201, $execution['headers']['status-code']);
            $this->assertEquals(200, $execution['body']['responseStatusCode']);

            $timeMs = ($end - $start) * 1000;
            $coldTimes[] = $timeMs;

            fwrite(STDOUT, 'Execution (cold): ' . $timeMs . 'ms' . PHP_EOL);
        }

        for ($i = 0; $i < 20; $i++) {
            $start = \microtime(true);
            $execution = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/executions', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()), [
                'async' => false,
            ]);
            $end = \microtime(true);

            $this->assertEquals(201, $execution['headers']['status-code']);
            $this->assertEquals(200, $execution['body']['responseStatusCode']);

            $timeMs = ($end - $start) * 1000;
            $warmTimes[] = $timeMs;

            fwrite(STDOUT, 'Execution (warm): ' . $timeMs . 'ms' . PHP_EOL);
        }

        $response = $this->client->call(Client::METHOD_DELETE, '/functions/' . $function['body']['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], []);

        $this->assertEquals(204, $response['headers']['status-code']);

        fwrite(STDOUT, 'Average time (cold): ' . \array_sum($coldTimes) / \count($coldTimes) . 'ms' . PHP_EOL);
        fwrite(STDOUT, 'Average time (warm): ' . \array_sum($warmTimes) / \count($warmTimes) . 'ms' . PHP_EOL);
    }
}
