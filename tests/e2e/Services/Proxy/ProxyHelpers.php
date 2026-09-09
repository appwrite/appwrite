<?php

namespace Tests\E2E\Services\Proxy;

use Appwrite\ID;
use Appwrite\Tests\Async;
use CURLFile;
use Tests\E2E\Client;
use Utopia\Console;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;

trait ProxyHelpers
{
    use Async;

    protected function listRules(array $params = []): mixed
    {
        $rule = $this->client->call(Client::METHOD_GET, '/proxy/rules', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), $params);

        return $rule;
    }

    protected function createAPIRule(string $domain): mixed
    {
        $rule = $this->client->call(Client::METHOD_POST, '/proxy/rules/api', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'domain' => $domain,
        ]);

        return $rule;
    }

    protected function updateRuleStatus(string $ruleId): mixed
    {
        $rule = $this->client->call(Client::METHOD_PATCH, '/proxy/rules/' . $ruleId . '/status', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        return $rule;
    }

    protected function createSiteRule(string $domain, string $siteId, string $branch = ''): mixed
    {
        $rule = $this->client->call(Client::METHOD_POST, '/proxy/rules/site', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'domain' => $domain,
            'siteId' => $siteId,
            'branch' => $branch,
        ]);

        return $rule;
    }

    protected function getRule(string $ruleId): mixed
    {
        $rule = $this->client->call(Client::METHOD_GET, '/proxy/rules/' . $ruleId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        return $rule;
    }

    protected function createRedirectRule(string $domain, string $url, int $statusCode, string $resourceType, string $resourceId): mixed
    {
        $rule = $this->client->call(Client::METHOD_POST, '/proxy/rules/redirect', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'domain' => $domain,
            'url' => $url,
            'statusCode' => $statusCode,
            'resourceType' => $resourceType,
            'resourceId' => $resourceId,
        ]);

        return $rule;
    }

    protected function createFunctionRule(string $domain, string $functionId, string $branch = ''): mixed
    {
        $rule = $this->client->call(Client::METHOD_POST, '/proxy/rules/function', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'domain' => $domain,
            'functionId' => $functionId,
            'branch' => $branch,
        ]);

        return $rule;
    }

    protected function createBucketRule(string $domain, string $bucketId): mixed
    {
        $rule = $this->client->call(Client::METHOD_POST, '/proxy/rules/bucket', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'domain' => $domain,
            'bucketId' => $bucketId,
        ]);

        return $rule;
    }

    protected function deleteRule(string $ruleId): mixed
    {
        $rule = $this->client->call(Client::METHOD_DELETE, '/proxy/rules/' . $ruleId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        return $rule;
    }

    protected function setupAPIRule(string $domain): string
    {
        $rule = $this->createAPIRule($domain);

        $this->assertEquals(201, $rule['headers']['status-code'], 'Failed to setup rule: ' . \json_encode($rule));

        return $rule['body']['$id'];
    }

    protected function setupRedirectRule(string $domain, string $url, int $statusCode, string $resourceType, string $resourceId): string
    {
        $rule = $this->createRedirectRule($domain, $url, $statusCode, $resourceType, $resourceId);

        $this->assertEquals(201, $rule['headers']['status-code'], 'Failed to setup rule: ' . \json_encode($rule));

        return $rule['body']['$id'];
    }

    protected function setupFunctionRule(string $domain, string $functionId, string $branch = ''): string
    {
        $rule = $this->createFunctionRule($domain, $functionId, $branch);

        $this->assertEquals(201, $rule['headers']['status-code'], 'Failed to setup rule: ' . \json_encode($rule));

        return $rule['body']['$id'];
    }

    protected function setupSiteRule(string $domain, string $siteId, string $branch = ''): string
    {
        $rule = $this->createSiteRule($domain, $siteId, $branch);

        $this->assertEquals(201, $rule['headers']['status-code'], 'Failed to setup rule: ' . \json_encode($rule));

        return $rule['body']['$id'];
    }

    protected function setupBucketRule(string $domain, string $bucketId): string
    {
        $rule = $this->createBucketRule($domain, $bucketId);

        $this->assertEquals(201, $rule['headers']['status-code'], 'Failed to setup rule: ' . \json_encode($rule));

        return $rule['body']['$id'];
    }

    protected function cleanupRule(string $ruleId): void
    {
        $rule = $this->deleteRule($ruleId);
        $this->assertEquals(204, $rule['headers']['status-code'], 'Failed to cleanup rule: ' . \json_encode($rule));
    }

    protected function cleanupSite(string $siteId): void
    {
        $site = $this->client->call(Client::METHOD_DELETE, '/sites/' . $siteId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(204, $site['headers']['status-code'], 'Failed to cleanup site: ' . \json_encode($site));
    }

    protected function cleanupFunction(string $functionId): void
    {
        $function = $this->client->call(Client::METHOD_DELETE, '/functions/' . $functionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(204, $function['headers']['status-code'], 'Failed to cleanup function: ' . \json_encode($function));
    }

    protected function cleanupBucket(string $bucketId): void
    {
        $bucket = $this->client->call(Client::METHOD_DELETE, '/storage/buckets/' . $bucketId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(204, $bucket['headers']['status-code'], 'Failed to cleanup bucket: ' . \json_encode($bucket));
    }

    protected function setupSite(): mixed
    {
        // Site
        $site = $this->client->call(Client::METHOD_POST, '/sites', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'siteId' => ID::unique(),
            'name' => 'Proxy site',
            'framework' => 'other',
            'adapter' => 'static',
            'buildRuntime' => 'static-1',
            'outputDirectory' => './',
            'buildCommand' => '',
            'installCommand' => '',
            'fallbackFile' => '',
        ]);

        $this->assertEquals($site['headers']['status-code'], 201, 'Setup site failed with status code: ' . $site['headers']['status-code'] . ' and response: ' . json_encode($site['body'], JSON_PRETTY_PRINT));

        $siteId = $site['body']['$id'];

        // Deployment
        $deployment = $this->client->call(Client::METHOD_POST, '/sites/' . $siteId . '/deployments', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'code' => $this->packageSite('static'),
            'activate' => 'true'
        ]);

        $this->assertEquals($deployment['headers']['status-code'], 202, 'Setup deployment failed with status code: ' . $deployment['headers']['status-code'] . ' and response: ' . json_encode($deployment['body'], JSON_PRETTY_PRINT));
        $deploymentId = $deployment['body']['$id'] ?? '';

        $this->assertEventually(function () use ($siteId, $deploymentId) {
            $site = $this->client->call(Client::METHOD_GET, '/sites/' . $siteId, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));
            $this->assertEquals($deploymentId, $site['body']['deploymentId'], 'Deployment is not activated, deployment: ' . json_encode($site['body'], JSON_PRETTY_PRINT));
        }, 120000, 500);

        return ['siteId' => $siteId, 'deploymentId' => $deploymentId];
    }

    protected function setupFunction(): mixed
    {
        // Function
        $function = $this->client->call(Client::METHOD_POST, '/functions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'functionId' => ID::unique(),
            'runtime' => 'node-22',
            'name' => 'Proxy Function',
            'entrypoint' => 'index.js',
            'commands' => '',
            'execute' => ['any']
        ]);

        $this->assertEquals($function['headers']['status-code'], 201, 'Setup function failed with status code: ' . $function['headers']['status-code'] . ' and response: ' . json_encode($function['body'], JSON_PRETTY_PRINT));

        $functionId = $function['body']['$id'];

        // Deployment
        $deployment = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/deployments', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'code' => $this->packageFunction('basic'),
            'activate' => 'true'
        ]);

        $this->assertEquals($deployment['headers']['status-code'], 202, 'Setup deployment failed with status code: ' . $deployment['headers']['status-code'] . ' and response: ' . json_encode($deployment['body'], JSON_PRETTY_PRINT));
        $deploymentId = $deployment['body']['$id'] ?? '';

        $this->assertEventually(function () use ($functionId, $deploymentId) {
            $function = $this->client->call(Client::METHOD_GET, '/functions/' . $functionId, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));
            $this->assertEquals($deploymentId, $function['body']['deploymentId'], 'Deployment is not activated, deployment: ' . json_encode($function['body'], JSON_PRETTY_PRINT));
        }, 100000, 500);

        return ['functionId' => $functionId, 'deploymentId' => $deploymentId];
    }

    protected function setupBucket(): mixed
    {
        // Bucket without read permission, so file permissions decide what guests can see
        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'bucketId' => ID::unique(),
            'name' => 'Proxy bucket',
            'fileSecurity' => true,
            'permissions' => [
                Permission::create(Role::any()),
            ],
        ]);

        $this->assertEquals($bucket['headers']['status-code'], 201, 'Setup bucket failed with status code: ' . $bucket['headers']['status-code'] . ' and response: ' . json_encode($bucket['body'], JSON_PRETTY_PRINT));

        $bucketId = $bucket['body']['$id'];

        // Public file at the bucket root, addressed by key "logo.png"
        $file = $this->setupFile($bucketId, 'logo.png', [Permission::read(Role::any())]);

        // Public file in a folder, addressed by key "photos/2026/pink.png"
        $folderFile = $this->setupFile($bucketId, 'pink.png', [Permission::read(Role::any())], 'photos/2026');

        // Private file, readable only through a file token
        $privateFile = $this->setupFile($bucketId, 'private.png', []);

        // Two files sharing one key, so that key is ambiguous and must be refused
        $twinA = $this->setupFile($bucketId, 'twin.png', [Permission::read(Role::any())]);
        $twinB = $this->setupFile($bucketId, 'twin.png', [Permission::read(Role::any())]);

        return [
            'bucketId' => $bucketId,
            'fileId' => $file['$id'],
            'folderFileId' => $folderFile['$id'],
            'privateFileId' => $privateFile['$id'],
            'twinIds' => [$twinA['$id'], $twinB['$id']],
        ];
    }

    protected function setupFile(string $bucketId, string $name, array $permissions, string $folder = ''): array
    {
        $params = [
            'fileId' => ID::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', $name),
        ];

        if (!empty($permissions)) {
            $params['permissions'] = $permissions;
        }

        if ($folder !== '') {
            $params['folder'] = $folder;
        }

        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), $params);

        $this->assertEquals($file['headers']['status-code'], 201, 'Setup file failed with status code: ' . $file['headers']['status-code'] . ' and response: ' . json_encode($file['body'], JSON_PRETTY_PRINT));

        return $file['body'];
    }

    private function packageSite(string $site): CURLFile
    {
        $stdout = '';
        $stderr = '';

        $folderPath = realpath(__DIR__ . '/../../../resources/sites') . "/$site";
        // Parallel tests must not truncate an archive another upload is reading.
        $tarPath = \sys_get_temp_dir() . '/appwrite-site-' . $site . '-' . \getmypid() . '-' . \uniqid('', true) . '.tar.gz';

        Console::execute(
            'tar --exclude code.tar.gz --exclude node_modules -czf ' . \escapeshellarg($tarPath) . ' -C ' . \escapeshellarg($folderPath) . ' .',
            '',
            $stdout,
            $stderr
        );

        if (filesize($tarPath) > 1024 * 1024 * 5) {
            throw new \Exception('Code package is too large. Use the chunked upload method instead.');
        }

        register_shutdown_function(static function () use ($tarPath) {
            if (\is_file($tarPath)) {
                @\unlink($tarPath);
            }
        });

        return new CURLFile($tarPath, 'application/x-gzip', 'code.tar.gz');
    }

    private function packageFunction(string $function): CURLFile
    {
        $stdout = '';
        $stderr = '';

        $folderPath = realpath(__DIR__ . '/../../../resources/functions') . "/$function";
        // Parallel tests must not truncate an archive another upload is reading.
        $tarPath = \sys_get_temp_dir() . '/appwrite-function-' . $function . '-' . \getmypid() . '-' . \uniqid('', true) . '.tar.gz';

        Console::execute(
            'tar --exclude code.tar.gz --exclude node_modules -czf ' . \escapeshellarg($tarPath) . ' -C ' . \escapeshellarg($folderPath) . ' .',
            '',
            $stdout,
            $stderr
        );

        if (filesize($tarPath) > 1024 * 1024 * 5) {
            throw new \Exception('Code package is too large. Use the chunked upload method instead.');
        }

        register_shutdown_function(static function () use ($tarPath) {
            if (\is_file($tarPath)) {
                @\unlink($tarPath);
            }
        });

        return new CURLFile($tarPath, 'application/x-gzip', 'code.tar.gz');
    }
}
