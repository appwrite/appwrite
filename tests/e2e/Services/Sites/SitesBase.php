<?php

namespace Tests\E2E\Services\Sites;

use Appwrite\Tests\Async;
use Appwrite\Tests\Async\Exceptions\Critical;
use CURLFile;
use Tests\E2E\Client;
use Utopia\CLI\Console;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;
use Utopia\System\System;

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

            if ($deployment['body']['status'] === 'failed') {
                throw new Critical('Deployment failed: ' . json_encode($deployment['body'], JSON_PRETTY_PRINT));
            }

            Console::execute("docker inspect openruntimes-executor --format='{{.State.ExitCode}}'", '', $this->stdout, $this->stderr);
            if (\trim($this->stdout) !== '0') {
                $msg = 'Executor has a problem: ' . $this->stderr . ' (' . $this->stdout . '), current status: ';

                Console::execute("docker compose logs openruntimes-executor", '', $this->stdout, $this->stderr);
                $msg .= $this->stdout . ' (' . $this->stderr . ')';

                throw new Critical($msg . json_encode($deployment['body'], JSON_PRETTY_PRINT));
            }

            $this->assertEquals('ready', $deployment['body']['status'], 'Deployment status is not ready, deployment: ' . json_encode($deployment['body'], JSON_PRETTY_PRINT));
        }, 300000, 500);

        // Not === so multipart/form-data works fine too
        if (($params['activate'] ?? false) == true) {
            $this->assertEventually(function () use ($siteId, $deploymentId) {
                $site = $this->client->call(Client::METHOD_GET, '/sites/' . $siteId, array_merge([
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id'],
                    'x-appwrite-key' => $this->getProject()['apiKey'],
                ]));
                $this->assertEquals($deploymentId, $site['body']['deploymentId'], 'Deployment is not activated, deployment: ' . json_encode($site['body'], JSON_PRETTY_PRINT));
            }, 100000, 500);
        }

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

    protected function cleanupDeployment(string $siteId, string $deploymentId): void
    {
        $deployment = $this->client->call(Client::METHOD_DELETE, '/sites/' . $siteId . '/deployments/' . $deploymentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]));

        $this->assertEquals($deployment['headers']['status-code'], 204);
    }

    protected function createSite(mixed $params): mixed
    {
        $site = $this->client->call(Client::METHOD_POST, '/sites', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), $params);

        return $site;
    }

    protected function updateSite(mixed $params): mixed
    {
        $site = $this->client->call(Client::METHOD_PUT, '/sites/' . $params['$id'], array_merge([
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

    protected function getVariable(string $siteId, string $variableId): mixed
    {
        $variable = $this->client->call(Client::METHOD_GET, '/sites/' . $siteId . '/variables/' . $variableId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        return $variable;
    }

    protected function listVariables(string $siteId, mixed $params = []): mixed
    {
        $variables = $this->client->call(Client::METHOD_GET, '/sites/' . $siteId . '/variables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), $params);

        return $variables;
    }

    protected function updateVariable(string $siteId, string $variableId, mixed $params): mixed
    {
        $variable = $this->client->call(Client::METHOD_PUT, '/sites/' . $siteId . '/variables/' . $variableId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), $params);

        return $variable;
    }

    protected function deleteVariable(string $siteId, string $variableId): mixed
    {
        $variable = $this->client->call(Client::METHOD_DELETE, '/sites/' . $siteId . '/variables/' . $variableId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

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

    protected function getLog(string $siteId, $logId): mixed
    {
        $log = $this->client->call(Client::METHOD_GET, '/sites/' . $siteId . '/logs/' . $logId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        return $log;
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

    protected function listLogs(string $siteId, array $queries = []): mixed
    {
        $logs = $this->client->call(Client::METHOD_GET, '/sites/' . $siteId . '/logs', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => $queries
        ]);

        return $logs;
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

    protected function deleteDeployment(string $siteId, string $deploymentId): mixed
    {
        $deployment = $this->client->call(Client::METHOD_DELETE, '/sites/' . $siteId . '/deployments/' . $deploymentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        return $deployment;
    }

    protected function setupDuplicateDeployment(string $siteId, string $deploymentId): string
    {
        $deployment = $this->createDuplicateDeployment($siteId, $deploymentId);
        $this->assertEquals(202, $deployment['headers']['status-code']);

        $deploymentId = $deployment['body']['$id'];
        $this->assertNotEmpty($deploymentId);

        $this->assertEventually(function () use ($siteId, $deploymentId) {
            $deployment = $this->getDeployment($siteId, $deploymentId);
            $this->assertEquals('ready', $deployment['body']['status'], 'Deployment status is not ready, deployment: ' . json_encode($deployment['body'], JSON_PRETTY_PRINT));
        }, 150000, 500);

        $this->assertEventually(function () use ($siteId, $deploymentId) {
            $site = $this->getSite($siteId);
            $this->assertEquals($deploymentId, $site['body']['deploymentId'], 'Deployment is not activated, deployment: ' . json_encode($site['body'], JSON_PRETTY_PRINT));
        }, 100000, 500);

        return $deploymentId;
    }

    protected function createDuplicateDeployment(string $siteId, string $deploymentId): mixed
    {
        $deployment = $this->client->call(Client::METHOD_POST, '/sites/' . $siteId . '/deployments/duplicate', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'deploymentId' => $deploymentId,
        ]);

        return $deployment;
    }

    protected function createTemplateDeployment(string $siteId, mixed $params = []): mixed
    {
        $deployment = $this->client->call(Client::METHOD_POST, '/sites/' . $siteId . '/deployments/template', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), $params);

        return $deployment;
    }

    protected function getUsage(string $siteId, mixed $params): mixed
    {
        $usage = $this->client->call(Client::METHOD_GET, '/sites/' . $siteId . '/usage', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), $params);

        return $usage;
    }

    protected function getTemplate(string $templateId)
    {
        $template = $this->client->call(Client::METHOD_GET, '/sites/templates/' . $templateId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]);

        return $template;
    }

    protected function helperGetLatestCommit(string $owner, string $repository): ?string
    {
        $ch = curl_init("https://api.github.com/repos/{$owner}/{$repository}/commits/main");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'User-Agent: Appwrite',
            'Accept: application/vnd.github.v3+json'
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            $commitData = json_decode($response, true);
            if (isset($commitData['sha'])) {
                return $commitData['sha'];
            }
        }

        return null;
    }

    protected function deleteSite(string $siteId): mixed
    {
        $site = $this->client->call(Client::METHOD_DELETE, '/sites/' . $siteId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        return $site;
    }

    protected function setupSiteDomain(string $siteId, string $subdomain = ''): string
    {
        $subdomain = $subdomain ? $subdomain : ID::unique();
        $rule = $this->client->call(Client::METHOD_POST, '/proxy/rules/site', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'domain' => $subdomain . '.' . System::getEnv('_APP_DOMAIN_SITES', ''),
            'siteId' => $siteId,
        ]);

        $this->assertEquals(201, $rule['headers']['status-code']);
        $this->assertNotEmpty($rule['body']['$id']);
        $this->assertNotEmpty($rule['body']['domain']);

        $domain = $rule['body']['domain'];

        return $domain;
    }

    protected function getSiteDomain(string $siteId): string
    {
        $rules = $this->client->call(Client::METHOD_GET, '/proxy/rules', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::equal('deploymentResourceId', [$siteId])->toString(),
                Query::equal('trigger', ['manual'])->toString(),
                Query::equal('type', ['deployment'])->toString(),
            ],
        ]);

        $this->assertEquals(200, $rules['headers']['status-code']);
        $this->assertGreaterThanOrEqual(1, $rules['body']['total']);
        $this->assertGreaterThanOrEqual(1, \count($rules['body']['rules']));
        $this->assertNotEmpty($rules['body']['rules'][0]['domain']);

        $domain = $rules['body']['rules'][0]['domain'];

        return $domain;
    }

    protected function getDeploymentDomain(string $deploymentId): string
    {
        $rules = $this->client->call(Client::METHOD_GET, '/proxy/rules', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::equal('deploymentId', [$deploymentId])->toString(),
                Query::equal('type', ['deployment'])->toString(),
                Query::equal('trigger', ['deployment'])->toString(),
            ],
        ]);

        $this->assertEquals(200, $rules['headers']['status-code']);
        $this->assertGreaterThanOrEqual(1, $rules['body']['total']);
        $this->assertGreaterThanOrEqual(1, \count($rules['body']['rules']));
        $this->assertNotEmpty($rules['body']['rules'][0]['domain']);

        $domain = $rules['body']['rules'][0]['domain'];

        return $domain;
    }

    protected function getDeploymentDownload(string $siteId, string $deploymentId, string $type): mixed
    {
        $response = $this->client->call(Client::METHOD_GET, '/sites/' . $siteId . '/deployments/' . $deploymentId . '/download', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'type' => $type,
        ]);

        return $response;
    }

    protected function updateSiteDeployment(string $siteId, string $deploymentId): mixed
    {
        $site = $this->client->call(Client::METHOD_PATCH, '/sites/' . $siteId . '/deployment', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'deploymentId' => $deploymentId
        ]);

        return $site;
    }

    protected function cancelDeployment(string $siteId, string $deploymentId): mixed
    {
        $deployment = $this->client->call(Client::METHOD_PATCH, '/sites/' . $siteId . '/deployments/' . $deploymentId . '/status', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        return $deployment;
    }

    protected function listSpecifications(): mixed
    {
        $specifications = $this->client->call(Client::METHOD_GET, '/sites/specifications', array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        return $specifications;
    }
}
