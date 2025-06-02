<?php

namespace Tests\E2E\Services\Sites;

use Appwrite\Tests\Async;
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

    protected array $prepared = []; // array of folder names of test resources that are already zipped

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
        if ($params['code'] instanceof CURLFile) {
            $deployment = $this->client->call(Client::METHOD_POST, '/sites/' . $siteId . '/deployments', array_merge([
                'content-type' => 'multipart/form-data',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]), $params);

            $this->assertEquals($deployment['headers']['status-code'], 202, 'Setup deployment failed with status code: ' . $deployment['headers']['status-code'] . ' and response: ' . json_encode($deployment['body'], JSON_PRETTY_PRINT));

            $deploymentId = $deployment['body']['$id'] ?? '';
        } elseif (\is_string($params['code'])) {
            $source = realpath(__DIR__ . '/../../../resources/sites') . "/" . $params['code'] . "/code.tar.gz";
            $chunkSize = 5 * 1024 * 1024;
            $handle = @fopen($source, "rb");
            $mimeType = mime_content_type($source);
            $counter = 0;
            $size = filesize($source);
            $headers = [
                'content-type' => 'multipart/form-data',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ];
            $deploymentId = '';
            while (!feof($handle)) {
                $curlFile = new \CURLFile('data://' . $mimeType . ';base64,' . base64_encode(@fread($handle, $chunkSize)), $mimeType, 'code.tar.gz');

                $headers['content-range'] = 'bytes ' . ($counter * $chunkSize) . '-' . min(((($counter * $chunkSize) + $chunkSize) - 1), $size - 1) . '/' . $size;

                if (!empty($deploymentId)) {
                    $headers['x-appwrite-id'] = $deploymentId;
                }

                $deployment = $this->client->call(Client::METHOD_POST, '/sites/' . $siteId . '/deployments', array_merge($headers), array_merge($params, [
                    'code' => $curlFile
                ]));

                $counter++;
                $deploymentId = $deployment['body']['$id'] ?? '';

                $this->assertEquals(202, $deployment['headers']['status-code']);
            }

            @fclose($handle);
        } else {
            throw new \Exception('Code parameter missing.');
        }

        $this->assertEventually(function () use ($siteId, $deploymentId) {
            $deployment = $this->client->call(Client::METHOD_GET, '/sites/' . $siteId . '/deployments/' . $deploymentId, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]));
            $this->assertEquals('ready', $deployment['body']['status'], 'Deployment status is not ready, deployment: ' . json_encode($deployment['body'], JSON_PRETTY_PRINT));
        }, 150000, 500);

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

    protected function packageSite(string $site, bool $silent = true): CURLFile | string
    {
        $folderPath = realpath(__DIR__ . '/../../../resources/sites') . "/$site";
        $tarPath = "$folderPath/code.tar.gz";

        if (!\in_array($site, $this->prepared)) {
            Console::execute("cd $folderPath && curl -fsSL https://bun.sh/install | bash && bun install", '', $this->stdout, $this->stderr);

            Console::execute("cd $folderPath && tar --exclude code.tar.gz -czf code.tar.gz .", '', $this->stdout, $this->stderr);

            $this->prepared[] = $site;
        }

        if (filesize($tarPath) > 1024 * 1024 * 5) {
            if ($silent) {
                return $site;
            }

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
        }, 100000, 500);

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
