<?php

namespace Appwrite\Builds;

use Executor\Exception as ExecutorException;
use Executor\Exception\Timeout as ExecutorTimeout;
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

    public function createJob(array $payload, int $timeout): array
    {
        return $this->call('POST', '/v1/jobs', $payload, $timeout);
    }

    public function deleteJob(string $jobId): void
    {
        $this->call('DELETE', '/v1/jobs/' . \rawurlencode($jobId), [], 30);
    }

    private function call(string $method, string $path, array $payload, int $timeout): array
    {
        if (empty($this->endpoint)) {
            throw new ExecutorException('Orchestrator host is not configured');
        }

        $headers = ['content-type: application/json'];
        if (!empty($this->apiKey)) {
            $headers[] = 'authorization: Bearer ' . $this->apiKey;
        }

        $ch = \curl_init($this->endpoint . $path);
        \curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        \curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        \curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        \curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);

        if ($method !== 'GET' && $method !== 'DELETE') {
            \curl_setopt($ch, CURLOPT_POSTFIELDS, \json_encode($payload));
        }

        $body = \curl_exec($ch);
        $status = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = \curl_errno($ch);
        $errorMessage = \curl_error($ch);

        if ($error) {
            if ($error === CURLE_OPERATION_TIMEDOUT) {
                throw new ExecutorTimeout('Orchestrator request timed out', $timeout);
            }
            throw new ExecutorException($errorMessage, $status);
        }

        if ($status >= 400) {
            throw new ExecutorException(\is_string($body) ? $body : 'Orchestrator request failed', $status);
        }

        if (empty($body)) {
            return [];
        }

        $decoded = \json_decode((string) $body, true);
        return \is_array($decoded) ? $decoded : [];
    }
}
