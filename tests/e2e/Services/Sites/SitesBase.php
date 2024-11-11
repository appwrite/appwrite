<?php

namespace Tests\E2E\Services\Sites;

use Appwrite\Tests\Async;
use CURLFile;
use Tests\E2E\Client;
use Utopia\CLI\Console;

trait SitesBase
{
    use Async;

    protected string $stdout = '';
    protected string $stderr = '';

    protected function setupSite(mixed $params): string
    {
        $site = $this->client->call(Client::METHOD_POST, '/sites', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), $params);

        $this->assertEquals($site['headers']['status-code'], 201, 'Setup site failed with status code: ' . $site['headers']['status-code'] . ' and response: ' . json_encode($site['body'], JSON_PRETTY_PRINT));

        $siteId = $site['body']['$id'];

        return $siteId;
    }

    protected function setupDeployment(string $siteId, mixed $params): string
    {
        $deployment = $this->client->call(Client::METHOD_POST, '/sites/' . $siteId . '/deployments', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), $params);
        $this->assertEquals($deployment['headers']['status-code'], 202, 'Setup deployment failed with status code: ' . $deployment['headers']['status-code'] . ' and response: ' . json_encode($deployment['body'], JSON_PRETTY_PRINT));
        $deploymentId = $deployment['body']['$id'] ?? '';

        $this->assertEventually(function () use ($siteId, $deploymentId) {
            $deployment = $this->client->call(Client::METHOD_GET, '/sites/' . $siteId . '/deployments/' . $deploymentId, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]));
            $this->assertEquals('ready', $deployment['body']['status'], 'Deployment status is not ready, deployment: ' . json_encode($deployment['body'], JSON_PRETTY_PRINT));
        }, 50000, 500);

        return $deploymentId;
    }

    protected function cleanupSite(string $siteId): void
    {
        $site = $this->client->call(Client::METHOD_DELETE, '/sites/' . $siteId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]));

        $this->assertEquals($site['headers']['status-code'], 204);
    }

    protected function createSite(mixed $params): mixed
    {
        $site = $this->client->call(Client::METHOD_POST, '/sites', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), $params);

        return $site;
    }

    protected function createVariable(string $siteId, mixed $params): mixed
    {
        $variable = $this->client->call(Client::METHOD_POST, '/sites/' . $siteId . '/variables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), $params);

        return $variable;
    }

    protected function getSite(string $siteId): mixed
    {
        $site = $this->client->call(Client::METHOD_GET, '/sites/' . $siteId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        return $site;
    }

    protected function getDeployment(string $siteId, string $deploymentId): mixed
    {
        $deployment = $this->client->call(Client::METHOD_GET, '/sites/' . $siteId . '/deployments/' . $deploymentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        return $deployment;
    }

    protected function listSites(mixed $params = []): mixed
    {
        $sites = $this->client->call(Client::METHOD_GET, '/sites', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), $params);

        return $sites;
    }

    protected function listDeployments(string $siteId, $params = []): mixed
    {
        $deployments = $this->client->call(Client::METHOD_GET, '/sites/' . $siteId . '/deployments', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), $params);

        return $deployments;
    }

    protected function packageSite(string $site): CURLFile
    {
        $folderPath = realpath(__DIR__ . '/../../../resources/sites') . "/$site";
        $tarPath = "$folderPath/code.tar.gz";

        Console::execute("cd $folderPath && tar --exclude code.tar.gz -czf code.tar.gz .", '', $this->stdout, $this->stderr);

        if (filesize($tarPath) > 1024 * 1024 * 5) {
            throw new \Exception('Code package is too large. Use the chunked upload method instead.');
        }

        return new CURLFile($tarPath, 'application/x-gzip', \basename($tarPath));
    }

    protected function createDeployment(string $siteId, mixed $params = []): mixed
    {
        $deployment = $this->client->call(Client::METHOD_POST, '/sites/' . $siteId . '/deployments', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), $params);

        return $deployment;
    }

    protected function getSiteUsage(string $siteId, mixed $params): mixed
    {
        $usage = $this->client->call(Client::METHOD_GET, '/sites/' . $siteId . '/usage', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), $params);

        return $usage;
    }

    protected function getTemplate(string $templateId)
    {
        $template = $this->client->call(Client::METHOD_GET, '/sites/templates/' . $templateId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        return $template;
    }

    protected function deleteSite(string $siteId): mixed
    {
        $site = $this->client->call(Client::METHOD_DELETE, '/sites/' . $siteId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        return $site;
    }
}
