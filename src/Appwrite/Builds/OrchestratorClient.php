<?php

namespace Appwrite\Builds;

use Executor\Exception as ExecutorException;
use Executor\Exception\Timeout as ExecutorTimeout;
use OpenRuntimes\Orchestrator\Client as OrchestratorSdkClient;
use OpenRuntimes\Orchestrator\DTO\JobRequest;
use OpenRuntimes\Orchestrator\Exception\ApiException as OrchestratorApiException;
use OpenRuntimes\Orchestrator\Exception\OrchestratorException;
use OpenRuntimes\Orchestrator\Exception\TimeoutException as OrchestratorTimeout;
use Utopia\System\System;

class OrchestratorClient
{
    private string $endpoint;
    private string $apiKey;

    public function __construct()
    {
        $this->endpoint = \rtrim(System::getEnv('_APP_ORCHESTRATOR_HOST', ''), '/');
        $this->apiKey = System::getEnv('_APP_ORCHESTRATOR_API_KEY', '');
    }

    public function createJob(JobRequest $request, int $timeout): array
    {
        try {
            $response = $this->client()->jobs()->create($request, $timeout);

            return [
                'id' => $response->id,
                'status' => $response->status->value,
            ];
        } catch (OrchestratorTimeout $e) {
            throw new ExecutorTimeout($e->getMessage(), $e->timeoutSeconds, previous: $e);
        } catch (OrchestratorApiException $e) {
            throw new ExecutorException($e->body !== '' ? $e->body : $e->getMessage(), $e->statusCode, $e);
        } catch (OrchestratorException $e) {
            throw new ExecutorException($e->getMessage(), $e->getCode(), $e);
        }
    }

    public function deleteJob(string $jobId): void
    {
        try {
            $this->client()->jobs()->delete($jobId);
        } catch (OrchestratorTimeout $e) {
            throw new ExecutorTimeout($e->getMessage(), $e->timeoutSeconds, previous: $e);
        } catch (OrchestratorApiException $e) {
            throw new ExecutorException($e->body !== '' ? $e->body : $e->getMessage(), $e->statusCode, $e);
        } catch (OrchestratorException $e) {
            throw new ExecutorException($e->getMessage(), $e->getCode(), $e);
        }
    }

    private function client(): OrchestratorSdkClient
    {
        if (empty($this->endpoint)) {
            throw new ExecutorException('Orchestrator host is not configured');
        }

        return new OrchestratorSdkClient(
            endpoint: $this->endpoint,
            apiKey: $this->apiKey !== '' ? $this->apiKey : null,
        );
    }
}
