<?php

namespace Tests\E2E\Services\Distribution;

use Appwrite\ID;
use Tests\E2E\Client;

trait DistributionBase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function routes(): iterable
    {
        yield 'createApp' => [Client::METHOD_POST, '/distribution/apps', 'distribution.write'];
        yield 'listApps' => [Client::METHOD_GET, '/distribution/apps', 'distribution.read'];
        yield 'getApp' => [Client::METHOD_GET, '/distribution/apps/missing', 'distribution.read'];
        yield 'updateApp' => [Client::METHOD_PATCH, '/distribution/apps/missing', 'distribution.write'];
        yield 'deleteApp' => [Client::METHOD_DELETE, '/distribution/apps/missing', 'distribution.write'];
        yield 'createTarget' => [Client::METHOD_POST, '/distribution/apps/missing/targets', 'distribution.write'];
        yield 'listTargets' => [Client::METHOD_GET, '/distribution/apps/missing/targets', 'distribution.read'];
        yield 'getTarget' => [Client::METHOD_GET, '/distribution/apps/missing/targets/missing', 'distribution.read'];
        yield 'updateTarget' => [Client::METHOD_PATCH, '/distribution/apps/missing/targets/missing', 'distribution.write'];
        yield 'deleteTarget' => [Client::METHOD_DELETE, '/distribution/apps/missing/targets/missing', 'distribution.write'];
        yield 'createVariable' => [Client::METHOD_POST, '/distribution/apps/missing/variables', 'distribution.write'];
        yield 'listVariables' => [Client::METHOD_GET, '/distribution/apps/missing/variables', 'distribution.read'];
        yield 'getVariable' => [Client::METHOD_GET, '/distribution/apps/missing/variables/missing', 'distribution.read'];
        yield 'updateVariable' => [Client::METHOD_PATCH, '/distribution/apps/missing/variables/missing', 'distribution.write'];
        yield 'deleteVariable' => [Client::METHOD_DELETE, '/distribution/apps/missing/variables/missing', 'distribution.write'];
    }

    /**
     * @return list<string>
     */
    public static function scopes(): array
    {
        return [
            'distribution.read',
            'distribution.write',
            'distribution.credentials.read',
            'distribution.credentials.write',
            'distribution.submissions.read',
            'distribution.submissions.write',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function headers(): array
    {
        return \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders());
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    protected function createApp(array $params = []): array
    {
        return $this->client->call(Client::METHOD_POST, '/distribution/apps', $this->headers(), \array_merge([
            'appId' => ID::unique(),
            'name' => 'App ' . \uniqid(),
            'framework' => 'flutter',
        ], $params));
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    protected function createTarget(string $appId, array $params = []): array
    {
        return $this->client->call(Client::METHOD_POST, '/distribution/apps/' . $appId . '/targets', $this->headers(), \array_merge([
            'targetId' => ID::unique(),
            'platform' => 'android',
            'identifier' => 'io.appwrite.t' . \uniqid(),
        ], $params));
    }
}
