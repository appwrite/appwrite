<?php

namespace Tests\E2E\Services\Proxy;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\E2E\Client;
use Utopia\Database\Query;
use Utopia\System\System;

trait ProxyBase
{
    use ProxyHelpers;

    protected function tearDown(): void
    {
        // Cleanup fixture rules left by failed verification tests
        $rules = $this->listRules([
            'queries' => [
                Query::endsWith('domain', 'webapp.com')->toString(),
                Query::limit(1000)->toString(),
            ]
        ]);
        $this->assertEquals(200, $rules['headers']['status-code']);
        foreach ($rules['body']['rules'] as $rule) {
            $ruleId = $rule['$id'];
            $response = $this->deleteRule($ruleId);
            $this->assertEquals(204, $response['headers']['status-code']);
        }

        if ($rules['body']['total'] > 0) {
            $rules = $this->listRules([
                'queries' => [
                    Query::endsWith('domain', 'webapp.com')->toString(),
                    Query::limit(1)->toString()
                ]
            ]);
            $this->assertEquals(200, $rules['headers']['status-code']);
            $this->assertSame(0, count($rules['body']['rules']));
            $this->assertEquals(0, $rules['body']['total']);
        }
    }

    public function testDeleteFunctionRules(): void
    {
        /**
         * Test for SUCCESS
         */
        $functionId = $this->setupFunction(deploy: false)['functionId'];
        $domain = \uniqid() . '-deleted-function.custom.localhost';
        $ruleId = $this->setupFunctionRule($domain, $functionId);

        $this->cleanupFunction($functionId);

        // No polling: deletion must release the domain before responding.
        $rule = $this->getRule($ruleId);
        $this->assertEquals(404, $rule['headers']['status-code']);
        $this->assertSame('rule_not_found', $rule['body']['type']);
        $rules = $this->listRules(['queries' => [Query::equal('domain', [$domain])->toString()]]);
        $this->assertEquals(200, $rules['headers']['status-code']);
        $this->assertSame(0, $rules['body']['total']);
    }

    public function testDeleteSiteRules(): void
    {
        /**
         * Test for SUCCESS
         */
        $siteId = $this->setupSite(deploy: false)['siteId'];
        $domain = \uniqid() . '-deleted-site.custom.localhost';
        $ruleId = $this->setupSiteRule($domain, $siteId);

        $this->cleanupSite($siteId);

        $rule = $this->getRule($ruleId);
        $this->assertEquals(404, $rule['headers']['status-code']);
        $this->assertSame('rule_not_found', $rule['body']['type']);
        $rules = $this->listRules(['queries' => [Query::equal('domain', [$domain])->toString()]]);
        $this->assertEquals(200, $rules['headers']['status-code']);
        $this->assertSame(0, $rules['body']['total']);
    }

    public function testDeleteFunctionRecreateId(): void
    {
        /**
         * Test for SUCCESS
         */
        $functionId = $this->setupFunction(deploy: false)['functionId'];
        $domain = \uniqid() . '-recreated-function.custom.localhost';
        $this->setupFunctionRule($domain, $functionId);
        $this->cleanupFunction($functionId);

        $replacement = $this->setupFunction($functionId, deploy: false);
        $this->assertSame($functionId, $replacement['functionId']);
        $rules = $this->listRules(['queries' => [
            Query::equal('deploymentResourceType', ['function'])->toString(),
            Query::equal('deploymentResourceId', [$functionId])->toString(),
            Query::equal('domain', [$domain])->toString(),
        ]]);
        $this->assertEquals(200, $rules['headers']['status-code']);
        $this->assertSame(0, $rules['body']['total']);

        $ruleId = $this->setupFunctionRule($domain, $functionId);
        $rule = $this->getRule($ruleId);
        $this->assertEquals(200, $rule['headers']['status-code']);
        $this->assertSame($functionId, $rule['body']['deploymentResourceId']);
        $this->cleanupFunction($functionId);
    }

    public function testDeleteSiteRecreateId(): void
    {
        /**
         * Test for SUCCESS
         */
        $siteId = $this->setupSite(deploy: false)['siteId'];
        $domain = \uniqid() . '-recreated-site.custom.localhost';
        $this->setupSiteRule($domain, $siteId);
        $this->cleanupSite($siteId);

        $replacement = $this->setupSite($siteId, deploy: false);
        $this->assertSame($siteId, $replacement['siteId']);
        $rules = $this->listRules(['queries' => [
            Query::equal('deploymentResourceType', ['site'])->toString(),
            Query::equal('deploymentResourceId', [$siteId])->toString(),
            Query::equal('domain', [$domain])->toString(),
        ]]);
        $this->assertEquals(200, $rules['headers']['status-code']);
        $this->assertSame(0, $rules['body']['total']);

        $ruleId = $this->setupSiteRule($domain, $siteId);
        $rule = $this->getRule($ruleId);
        $this->assertEquals(200, $rule['headers']['status-code']);
        $this->assertSame($siteId, $rule['body']['deploymentResourceId']);
        $this->cleanupSite($siteId);
    }

    public function testDeleteFunctionReassignDomain(): void
    {
        /**
         * Test for SUCCESS
         */
        $original = $this->setupFunction(deploy: false)['functionId'];
        $replacement = $this->setupFunction(deploy: false)['functionId'];
        $domain = \uniqid() . '-reassigned-function.custom.localhost';
        $this->setupFunctionRule($domain, $original);

        /**
         * Test for FAILURE
         */
        $duplicate = $this->createFunctionRule($domain, $replacement);
        $this->assertEquals(409, $duplicate['headers']['status-code']);

        /**
         * Test for SUCCESS
         */
        $this->cleanupFunction($original);
        $ruleId = $this->setupFunctionRule($domain, $replacement);
        $rule = $this->getRule($ruleId);
        $this->assertEquals(200, $rule['headers']['status-code']);
        $this->assertSame($replacement, $rule['body']['deploymentResourceId']);
        $this->assertSame($domain, $rule['body']['domain']);
        $this->cleanupFunction($replacement);
    }

    public function testDeleteSiteReassignDomain(): void
    {
        /**
         * Test for SUCCESS
         */
        $original = $this->setupSite(deploy: false)['siteId'];
        $replacement = $this->setupSite(deploy: false)['siteId'];
        $domain = \uniqid() . '-reassigned-site.custom.localhost';
        $this->setupSiteRule($domain, $original);

        /**
         * Test for FAILURE
         */
        $duplicate = $this->createSiteRule($domain, $replacement);
        $this->assertEquals(409, $duplicate['headers']['status-code']);

        /**
         * Test for SUCCESS
         */
        $this->cleanupSite($original);
        $ruleId = $this->setupSiteRule($domain, $replacement);
        $rule = $this->getRule($ruleId);
        $this->assertEquals(200, $rule['headers']['status-code']);
        $this->assertSame($replacement, $rule['body']['deploymentResourceId']);
        $this->assertSame($domain, $rule['body']['domain']);
        $this->cleanupSite($replacement);
    }

    public function testDeleteFunctionPreservesSiteRules(): void
    {
        /**
         * Test for SUCCESS
         */
        $id = $this->setupFunction(deploy: false)['functionId'];
        $this->setupSite($id, deploy: false);
        $functionRule = $this->setupFunctionRule(\uniqid() . '-function.custom.localhost', $id);
        $siteRule = $this->setupSiteRule(\uniqid() . '-site.custom.localhost', $id);

        $this->cleanupFunction($id);

        $this->assertEquals(404, $this->getRule($functionRule)['headers']['status-code']);
        $rule = $this->getRule($siteRule);
        $this->assertEquals(200, $rule['headers']['status-code']);
        $this->assertSame('site', $rule['body']['deploymentResourceType']);
        $this->assertSame($id, $rule['body']['deploymentResourceId']);
        $this->cleanupSite($id);
    }

    public function testDeleteFunctionPreservesUnrelatedRules(): void
    {
        /**
         * Test for SUCCESS
         */
        $deleted = $this->setupFunction(deploy: false)['functionId'];
        $retained = $this->setupFunction(deploy: false)['functionId'];
        $deletedRule = $this->setupFunctionRule(\uniqid() . '-deleted.custom.localhost', $deleted);
        $retainedRule = $this->setupFunctionRule(\uniqid() . '-retained.custom.localhost', $retained);
        $apiRule = $this->setupAPIRule(\uniqid() . '-api.custom.localhost');

        $this->cleanupFunction($deleted);

        $this->assertEquals(404, $this->getRule($deletedRule)['headers']['status-code']);
        $this->assertEquals(200, $this->getRule($retainedRule)['headers']['status-code']);
        $this->assertEquals(200, $this->getRule($apiRule)['headers']['status-code']);
        $this->cleanupFunction($retained);
        $this->cleanupRule($apiRule);
    }

    #[DataProvider('resources')]
    public function testDeleteRedirectRules(string $type): void
    {
        /**
         * Test for SUCCESS
         */
        $resourceId = $type === 'function'
            ? $this->setupFunction(deploy: false)['functionId']
            : $this->setupSite(deploy: false)['siteId'];
        $domain = \uniqid() . '-deleted-redirect.custom.localhost';
        $ruleId = $this->setupRedirectRule($domain, 'https://example.com/target', 307, $type, $resourceId);
        $proxy = new Client();
        $proxy->setEndpoint('http://appwrite.test');
        $proxy->addHeader('x-appwrite-hostname', $domain);
        $response = $proxy->call(Client::METHOD_GET, '/', followRedirects: false);
        $this->assertEquals(307, $response['headers']['status-code']);
        $this->assertSame('https://example.com/target', $response['headers']['location']);

        if ($type === 'function') {
            $this->cleanupFunction($resourceId);
        } else {
            $this->cleanupSite($resourceId);
        }

        $this->assertEquals(404, $this->getRule($ruleId)['headers']['status-code']);
        $response = $proxy->call(Client::METHOD_GET, '/', followRedirects: false);
        $this->assertEquals(401, $response['headers']['status-code']);
    }

    public static function resources(): \Iterator
    {
        yield 'function' => ['function'];
        yield 'site' => ['site'];
    }

    #[DataProvider('resources')]
    public function testDeleteDeploymentAndBranchRules(string $type): void
    {
        /**
         * Test for SUCCESS
         */
        $resourceId = $type === 'function'
            ? $this->setupFunction()['functionId']
            : $this->setupSite()['siteId'];
        $manualDomain = \uniqid() . '-deleted-manual.custom.localhost';
        $manualRuleId = $type === 'function'
            ? $this->setupFunctionRule($manualDomain, $resourceId)
            : $this->setupSiteRule($manualDomain, $resourceId);
        $domain = \uniqid() . '-deleted-branch.custom.localhost';
        $ruleId = $type === 'function'
            ? $this->setupFunctionRule($domain, $resourceId, 'dev')
            : $this->setupSiteRule($domain, $resourceId, 'dev');
        $rule = $this->getRule($ruleId);
        $this->assertEquals(200, $rule['headers']['status-code']);
        $this->assertSame('dev', $rule['body']['deploymentVcsProviderBranch']);
        $queries = [
            Query::equal('deploymentResourceType', [$type])->toString(),
            Query::equal('deploymentResourceId', [$resourceId])->toString(),
        ];
        $rules = $this->listRules(['queries' => $queries]);
        $this->assertEquals(200, $rules['headers']['status-code']);
        $this->assertGreaterThan(1, $rules['body']['total']);

        if ($type === 'site') {
            $previews = $this->listRules(['queries' => [
                ...$queries,
                Query::equal('trigger', ['deployment'])->toString(),
            ]]);
            $this->assertEquals(200, $previews['headers']['status-code']);
            $this->assertGreaterThan(0, $previews['body']['total']);
        }

        if ($type === 'function') {
            $this->cleanupFunction($resourceId);
        } else {
            $this->cleanupSite($resourceId);
        }

        $rules = $this->listRules(['queries' => $queries]);
        $this->assertEquals(200, $rules['headers']['status-code']);
        $this->assertSame(0, $rules['body']['total']);
        $this->assertEquals(404, $this->getRule($ruleId)['headers']['status-code']);
        $this->assertEquals(404, $this->getRule($manualRuleId)['headers']['status-code']);
    }

    public function testCreateRule(): void
    {
        $domain = \uniqid() . '-api.myapp.com';
        $rule = $this->createAPIRule($domain);

        $this->assertEquals(201, $rule['headers']['status-code']);
        $this->assertEquals($domain, $rule['body']['domain']);
        $this->assertEquals('manual', $rule['body']['trigger']);
        $this->assertArrayHasKey('$id', $rule['body']);
        $this->assertArrayHasKey('domain', $rule['body']);
        $this->assertArrayHasKey('type', $rule['body']);
        $this->assertArrayHasKey('redirectUrl', $rule['body']);
        $this->assertArrayHasKey('redirectStatusCode', $rule['body']);
        $this->assertArrayHasKey('deploymentResourceType', $rule['body']);
        $this->assertArrayHasKey('deploymentId', $rule['body']);
        $this->assertArrayHasKey('deploymentResourceId', $rule['body']);
        $this->assertArrayHasKey('deploymentVcsProviderBranch', $rule['body']);
        $this->assertArrayHasKey('logs', $rule['body']);
        $this->assertArrayHasKey('renewAt', $rule['body']);

        $ruleId = $rule['body']['$id'];

        $rule = $this->createAPIRule($domain);
        $this->assertEquals(409, $rule['headers']['status-code']);

        $rule = $this->deleteRule($ruleId);

        $this->assertEquals(204, $rule['headers']['status-code']);
    }

    public function testCreateRuleDeletesOrphanedRule(): void
    {
        $domain = \uniqid() . '-orphan-api.custom.localhost';
        $orphanProject = $this->getProject(true);

        $orphanRule = $this->client->call(Client::METHOD_POST, '/proxy/rules/api', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $orphanProject['$id'],
            'x-appwrite-key' => $orphanProject['apiKey'],
        ], [
            'domain' => $domain,
        ]);

        $this->assertEquals(201, $orphanRule['headers']['status-code']);
        $this->assertEquals($domain, $orphanRule['body']['domain']);

        $duplicateRule = $this->createAPIRule($domain);
        $this->assertEquals(409, $duplicateRule['headers']['status-code']);

        $deleteProject = $this->client->call(Client::METHOD_DELETE, '/projects/' . $orphanProject['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $orphanProject['$id'],
            'x-appwrite-key' => $orphanProject['apiKey'],
        ]);

        $this->assertEquals(204, $deleteProject['headers']['status-code']);

        // Project deletion removes the project document synchronously, while rule cleanup is queued.
        // Creating the same domain now should clean up that orphaned rule before retrying.
        $rule = $this->createAPIRule($domain);

        $this->assertEquals(201, $rule['headers']['status-code']);
        $this->assertEquals($domain, $rule['body']['domain']);

        $rules = $this->listRules([
            'queries' => [
                Query::equal('domain', [$domain])->toString(),
            ],
        ]);

        $this->assertEquals(200, $rules['headers']['status-code']);
        $this->assertEquals(1, $rules['body']['total']);
        $this->assertEquals($rule['body']['$id'], $rules['body']['rules'][0]['$id']);

        $this->cleanupRule($rule['body']['$id']);
    }

    public function testCreateRuleSetup(): void
    {
        $ruleId = $this->setupAPIRule(\uniqid() . '-api2.myapp.com');
        $this->cleanupRule($ruleId);
    }

    public function testCreateRuleApex(): void
    {
        $domain = \uniqid() . '.com';
        $rule = $this->createAPIRule($domain);
        $this->assertEquals(201, $rule['headers']['status-code']);
        $this->assertEquals('unverified', $rule['body']['status']);
    }

    public function testCreateRuleVcs(): void
    {
        $domain = \uniqid() . '-vcs.myapp.com';

        $setup = $this->setupSite();
        $siteId = $setup['siteId'];
        $deploymentId = $setup['deploymentId'];

        $this->assertNotEmpty($siteId);
        $this->assertNotEmpty($deploymentId);

        $rule = $this->createSiteRule('commit-' . $domain, $siteId);
        $this->assertEquals(201, $rule['headers']['status-code']);
        $this->cleanupRule($rule['body']['$id']);

        $rule = $this->createSiteRule('branch-' . $domain, $siteId);
        $this->assertEquals(201, $rule['headers']['status-code']);
        $this->cleanupRule($rule['body']['$id']);

        $rule = $this->createSiteRule('anything-' . $domain, $siteId);
        $this->assertEquals(201, $rule['headers']['status-code']);
        $this->cleanupRule($rule['body']['$id']);

        $sitesDomain = \explode(',', System::getEnv('_APP_DOMAIN_SITES', ''))[0];
        $domain =  \uniqid() . '-vcs.' . $sitesDomain;

        $rule = $this->createSiteRule('commit-' . $domain, $siteId);
        $this->assertEquals(400, $rule['headers']['status-code']);

        $rule = $this->createSiteRule('branch-' . $domain, $siteId);
        $this->assertEquals(400, $rule['headers']['status-code']);

        $rule = $this->createSiteRule('subdomain.anything-' . $domain, $siteId);
        $this->assertEquals(400, $rule['headers']['status-code']);

        $rule = $this->createSiteRule('anything-' . $domain, $siteId);
        $this->assertEquals(201, $rule['headers']['status-code']);
        $this->cleanupRule($rule['body']['$id']);
    }

    public function testCreateAPIRule(): void
    {
        $domain = \uniqid() . '-api.custom.localhost';

        $proxyClient = new Client();
        $proxyClient->setEndpoint('http://appwrite.test');
        $proxyClient->addHeader('x-appwrite-hostname', $domain);

        $response = $proxyClient->call(Client::METHOD_GET, '/versions');
        $this->assertEquals(401, $response['headers']['status-code']);

        $ruleId = $this->setupAPIRule($domain);

        $this->assertNotEmpty($ruleId);

        $response = $proxyClient->call(Client::METHOD_GET, '/versions');
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(APP_VERSION_STABLE, $response['body']['server']);

        $this->cleanupRule($ruleId);

        $rule = $this->createAPIRule('http://' . $domain);
        $this->assertEquals(400, $rule['headers']['status-code']);

        $rule = $this->createAPIRule('https://' . $domain);
        $this->assertEquals(400, $rule['headers']['status-code']);

        $rule = $this->createAPIRule('wss://' . $domain);
        $this->assertEquals(400, $rule['headers']['status-code']);

        $rule = $this->createAPIRule($domain . '/some-path');
        $this->assertEquals(400, $rule['headers']['status-code']);
    }

    public function testCreateAPIRuleFoldsDomainCase(): void
    {
        $domain = \uniqid() . '-Case.Custom.LOCALHOST';
        $canonical = \strtolower($domain);

        $rule = $this->createAPIRule($domain);
        $this->assertEquals(201, $rule['headers']['status-code'], \json_encode($rule));
        $this->assertEquals($canonical, $rule['body']['domain']);

        // Re-read, so this pins what was persisted rather than how the create
        // response was shaped. The stored value is what the delete path and the
        // certificate providers are handed.
        $fetched = $this->getRule($rule['body']['$id']);
        $this->assertEquals(200, $fetched['headers']['status-code']);
        $this->assertEquals($canonical, $fetched['body']['domain']);

        // Deleting a mixed-case rule is the path that broke: a non-canonical
        // stored domain must not stop the rule from being removed.
        $this->cleanupRule($rule['body']['$id']);
    }

    public function testDeleteAPIRule(): void
    {
        $domain = \uniqid() . '-delete-api.custom.localhost';
        $proxyClient = new Client();
        $proxyClient->setEndpoint('http://appwrite.test');
        $proxyClient->addHeader('x-appwrite-hostname', $domain);

        /**
         * Test for SUCCESS
         */
        $ruleId = $this->setupAPIRule($domain);

        $response = $proxyClient->call(Client::METHOD_GET, '/versions');
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(APP_VERSION_STABLE, $response['body']['server']);

        $rule = $this->deleteRule($ruleId);
        $this->assertEquals(204, $rule['headers']['status-code']);

        $this->assertEventually(function () use ($proxyClient) {
            $response = $proxyClient->call(Client::METHOD_GET, '/versions');
            $this->assertEquals(401, $response['headers']['status-code']);
        });

        /**
         * Test for FAILURE
         */
        $rule = $this->getRule($ruleId);
        $this->assertEquals(404, $rule['headers']['status-code']);
        $this->assertEquals('rule_not_found', $rule['body']['type']);

        $rule = $this->deleteRule($ruleId);
        $this->assertEquals(404, $rule['headers']['status-code']);
        $this->assertEquals('rule_not_found', $rule['body']['type']);

        /**
         * Test for SUCCESS
         */
        $ruleId = $this->setupAPIRule($domain);

        $rule = $this->getRule($ruleId);
        $this->assertEquals(200, $rule['headers']['status-code']);
        $this->assertEquals($domain, $rule['body']['domain']);
        $this->assertEquals('api', $rule['body']['type']);

        $rules = $this->listRules([
            'queries' => [Query::equal('domain', [$domain])->toString()],
        ]);
        $this->assertEquals(200, $rules['headers']['status-code']);
        $this->assertEquals(1, $rules['body']['total']);
        $this->assertEquals($ruleId, $rules['body']['rules'][0]['$id']);

        $this->assertEventually(function () use ($proxyClient) {
            $response = $proxyClient->call(Client::METHOD_GET, '/versions');
            $this->assertEquals(200, $response['headers']['status-code']);
            $this->assertEquals(APP_VERSION_STABLE, $response['body']['server']);
        });

        $this->cleanupRule($ruleId);
    }

    public function testDeleteProjectRules(): void
    {
        $domain = \uniqid() . '-delete-project.custom.localhost';
        $project = $this->getProject(true);
        $proxyClient = new Client();
        $proxyClient->setEndpoint('http://appwrite.test');
        $proxyClient->addHeader('x-appwrite-hostname', $domain);

        /**
         * Test for SUCCESS
         */
        $rule = $this->client->call(Client::METHOD_POST, '/proxy/rules/api', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $project['$id'],
            'x-appwrite-key' => $project['apiKey'],
        ], [
            'domain' => $domain,
        ]);
        $this->assertEquals(201, $rule['headers']['status-code']);
        $this->assertEquals($domain, $rule['body']['domain']);

        $response = $proxyClient->call(Client::METHOD_GET, '/versions');
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(APP_VERSION_STABLE, $response['body']['server']);

        /**
         * Test for FAILURE
         */
        $rule = $this->createAPIRule($domain);
        $this->assertEquals(409, $rule['headers']['status-code']);

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_DELETE, '/projects/' . $project['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $project['$id'],
            'x-appwrite-key' => $project['apiKey'],
        ]);
        $this->assertEquals(204, $response['headers']['status-code']);

        // Project rules are removed by the deletes worker. Wait for queued
        // cleanup before recreating the domain.
        $this->assertEventually(function () use ($proxyClient) {
            $response = $proxyClient->call(Client::METHOD_GET, '/versions');
            $this->assertEquals(401, $response['headers']['status-code']);
        });

        $ruleId = $this->setupAPIRule($domain);
        $rule = $this->getRule($ruleId);
        $this->assertEquals(200, $rule['headers']['status-code']);
        $this->assertEquals($domain, $rule['body']['domain']);
        $this->assertEquals('api', $rule['body']['type']);

        $rules = $this->listRules([
            'queries' => [Query::equal('domain', [$domain])->toString()],
        ]);
        $this->assertEquals(200, $rules['headers']['status-code']);
        $this->assertEquals(1, $rules['body']['total']);
        $this->assertEquals($ruleId, $rules['body']['rules'][0]['$id']);

        $this->assertEventually(function () use ($proxyClient) {
            $response = $proxyClient->call(Client::METHOD_GET, '/versions');
            $this->assertEquals(200, $response['headers']['status-code']);
            $this->assertEquals(APP_VERSION_STABLE, $response['body']['server']);
        });

        $this->cleanupRule($ruleId);
    }

    public function testCreateRedirectRule(): void
    {
        $domain = \uniqid() . '-redirect.custom.localhost';

        $proxyClient = new Client();
        $proxyClient->setEndpoint('http://appwrite.test');
        $proxyClient->addHeader('x-appwrite-hostname', $domain);

        $response = $proxyClient->call(Client::METHOD_GET, '/todos/1');
        $this->assertEquals(401, $response['headers']['status-code']);

        $siteId = $this->setupSite()['siteId'];

        $ruleId301 = $this->setupRedirectRule($domain, 'https://jsonplaceholder.typicode.com/todos/1', 301, 'site', $siteId);
        $this->assertNotEmpty($ruleId301);

        $response = $proxyClient->call(Client::METHOD_GET, '/todos/1');
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(1, $response['body']['id']);

        $response = $proxyClient->call(Client::METHOD_GET, '/');
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(1, $response['body']['id']);

        $response = $proxyClient->call(Client::METHOD_GET, '/', followRedirects: false);
        $this->assertEquals(301, $response['headers']['status-code']);
        $this->assertEquals('https://jsonplaceholder.typicode.com/todos/1', $response['headers']['location']);

        $domain = \uniqid() . '-redirect-307.custom.localhost';
        $ruleId307 = $this->setupRedirectRule($domain, 'https://jsonplaceholder.typicode.com/todos/1', 307, 'site', $siteId);
        $this->assertNotEmpty($ruleId307);

        $proxyClient = new Client();
        $proxyClient->setEndpoint('http://appwrite.test');
        $proxyClient->addHeader('x-appwrite-hostname', $domain);

        $response = $proxyClient->call(Client::METHOD_GET, '/', followRedirects: false);
        $this->assertEquals(307, $response['headers']['status-code']);
        $this->assertEquals('https://jsonplaceholder.typicode.com/todos/1', $response['headers']['location']);

        $rules = $this->listRules([
            'queries' => [
                Query::equal('type', ['redirect'])->toString(),
                Query::equal('trigger', ['manual'])->toString(),
                Query::equal('deploymentResourceType', ['site'])->toString(),
                Query::equal('deploymentResourceId', [$siteId])->toString(),
            ],
        ]);
        $this->assertEquals(200, $rules['headers']['status-code']);
        $this->assertEquals(2, $rules['body']['total']);

        // Delete rules before the site to avoid cascade-delete races.
        $this->cleanupRule($ruleId301);
        $this->cleanupRule($ruleId307);
        $this->cleanupSite($siteId);
    }

    public function testCreateFunctionRule(): void
    {
        $domain = \uniqid() . '-function.custom.localhost';

        $proxyClient = new Client();
        $proxyClient->setEndpoint('http://appwrite.test');
        $proxyClient->addHeader('x-appwrite-hostname', $domain);

        $response = $proxyClient->call(Client::METHOD_GET, '/ping');
        $this->assertEquals(401, $response['headers']['status-code']);

        $setup = $this->setupFunction();
        $functionId = $setup['functionId'];
        $deploymentId = $setup['deploymentId'];

        $this->assertNotEmpty($functionId);
        $this->assertNotEmpty($deploymentId);

        $ruleId = $this->setupFunctionRule($domain, $functionId);
        $this->assertNotEmpty($ruleId);

        $response = $proxyClient->call(Client::METHOD_GET, '/ping');
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals($functionId, $response['body']['APPWRITE_FUNCTION_ID']);

        $this->cleanupRule($ruleId);

        $this->cleanupFunction($functionId);

        $this->assertEventually(function () use ($functionId, $deploymentId) {
            $rules = $this->listRules([
                'queries' => [
                    Query::limit(1)->toString(),
                    Query::equal('type', ['deployment'])->toString(),
                    Query::equal('deploymentResourceType', ['function'])->toString(),
                    Query::equal('deploymentResourceId', [$functionId])->toString(),
                ]
            ]);
            $this->assertEquals(200, $rules['headers']['status-code']);
            $this->assertEquals(0, $rules['body']['total']);
            $this->assertCount(0, $rules['body']['rules']);

            $rules = $this->listRules([
                'queries' => [
                    Query::limit(1)->toString(),
                    Query::equal('type', ['deployment'])->toString(),
                    Query::equal('deploymentId', [$deploymentId])->toString()
                ]
            ]);
            $this->assertEquals(200, $rules['headers']['status-code']);
            $this->assertEquals(0, $rules['body']['total']);
            $this->assertCount(0, $rules['body']['rules']);
        });
    }

    public function testCreateSiteRule(): void
    {
        $domain = \uniqid() . '-site.custom.localhost';

        $proxyClient = new Client();
        $proxyClient->setEndpoint('http://appwrite.test');
        $proxyClient->addHeader('x-appwrite-hostname', $domain);

        $response = $proxyClient->call(Client::METHOD_GET, '/contact');
        $this->assertEquals(401, $response['headers']['status-code']);

        $setup = $this->setupSite();
        $siteId = $setup['siteId'];
        $deploymentId = $setup['deploymentId'];

        $this->assertNotEmpty($siteId);
        $this->assertNotEmpty($deploymentId);

        $ruleId = $this->setupSiteRule($domain, $siteId);
        $this->assertNotEmpty($ruleId);
        $rule = $this->getRule($ruleId);
        $this->assertSame(200, $rule['headers']['status-code']);
        $this->assertSame('unverified', $rule['body']['status']);

        $response = $proxyClient->call(Client::METHOD_GET, '/contact');
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertStringContainsString('Contact page', $response['body']);

        // Wildcard domains automatically get verified status
        $domains = [
            \uniqid() . '.sites.localhost',
            \uniqid() . '.rebranded.localhost',
        ];
        foreach ($domains as $domain) {
            $wildcardRuleId = $this->setupSiteRule($domain, $siteId);
            $this->assertNotEmpty($wildcardRuleId);
            $rule = $this->getRule($wildcardRuleId);
            $this->assertSame(200, $rule['headers']['status-code']);
            $this->assertSame('verified', $rule['body']['status']);
            $this->cleanupRule($wildcardRuleId);
        }

        $rules = $this->listRules([
            'queries' => [
                Query::limit(1)->toString(),
                Query::equal('trigger', ['deployment'])->toString(),
                Query::equal('type', ['deployment'])->toString(),
                Query::equal('deploymentResourceType', ['site'])->toString(),
                Query::equal('deploymentResourceId', [$siteId])->toString(),
            ]
        ]);
        $this->assertEquals(200, $rules['headers']['status-code']);
        $this->assertGreaterThan(0, $rules['body']['total']);

        $this->cleanupRule($ruleId);

        $this->cleanupSite($siteId);

        $this->assertEventually(function () use ($siteId, $deploymentId) {
            $rules = $this->listRules([
                'queries' => [
                    Query::limit(1)->toString(),
                    Query::equal('type', ['deployment'])->toString(),
                    Query::equal('deploymentResourceType', ['site'])->toString(),
                    Query::equal('deploymentResourceId', [$siteId])->toString(),
                ]
            ]);
            $this->assertEquals(200, $rules['headers']['status-code']);
            $this->assertEquals(0, $rules['body']['total']);
            $this->assertCount(0, $rules['body']['rules']);

            $rules = $this->listRules([
                'queries' => [
                    Query::limit(1)->toString(),
                    Query::equal('type', ['deployment'])->toString(),
                    Query::equal('deploymentId', [$deploymentId])->toString()
                ]
            ]);
            $this->assertEquals(200, $rules['headers']['status-code']);
            $this->assertEquals(0, $rules['body']['total']);
            $this->assertCount(0, $rules['body']['rules']);
        });
    }

    public function testCreateSiteBranchRule(): void
    {
        $domain = \uniqid() . '-site-branch.custom.localhost';

        $setup = $this->setupSite();
        $siteId = $setup['siteId'];
        $deploymentId = $setup['deploymentId'];

        $this->assertNotEmpty($siteId);
        $this->assertNotEmpty($deploymentId);

        $ruleId = $this->setupSiteRule($domain, $siteId, 'dev');
        $this->assertNotEmpty($ruleId);

        $rule = $this->getRule($ruleId);
        $this->assertEquals(200, $rule['headers']['status-code']);

        $this->cleanupRule($ruleId);
    }

    public function testCreateFunctionBranchRule(): void
    {
        $domain = \uniqid() . '-function-branch.custom.localhost';

        $setup = $this->setupFunction();
        $functionId = $setup['functionId'];
        $deploymentId = $setup['deploymentId'];

        $this->assertNotEmpty($functionId);
        $this->assertNotEmpty($deploymentId);

        $ruleId = $this->setupFunctionRule($domain, $functionId, 'dev');
        $this->assertNotEmpty($ruleId);

        $rule = $this->getRule($ruleId);
        $this->assertEquals(200, $rule['headers']['status-code']);

        $this->cleanupRule($ruleId);

        $this->cleanupFunction($functionId);
    }

    public function testUpdateRule(): void
    {
        // Create function appwrite-network domain
        $functionsDomain = \explode(',', System::getEnv('_APP_DOMAIN_FUNCTIONS', ''))[0];
        $domain = \uniqid() . '-cname-api.' . $functionsDomain;

        $rule = $this->createAPIRule($domain);
        $this->assertEquals(201, $rule['headers']['status-code']);
        $this->assertEquals('verified', $rule['body']['status']);

        $this->cleanupRule($rule['body']['$id']);

        // Create site appwrite-network domain
        $sitesDomain = \explode(',', System::getEnv('_APP_DOMAIN_SITES', ''))[0];
        $domain = \uniqid() . '-cname-api.' . $sitesDomain;

        $rule = $this->createAPIRule($domain);
        $this->assertEquals(201, $rule['headers']['status-code']);
        $this->assertEquals('verified', $rule['body']['status']);

        $this->cleanupRule($rule['body']['$id']);

        // Create + update
        $domain = \uniqid() . '-cname-api.custom.com';

        $rule = $this->createAPIRule($domain);
        $this->assertEquals(201, $rule['headers']['status-code']);
        $this->assertEquals('unverified', $rule['body']['status']);

        $ruleId = $rule['body']['$id'];

        $rule = $this->updateRuleStatus($ruleId);
        $this->assertEquals(400, $rule['headers']['status-code']);

        $this->cleanupRule($ruleId);
    }

    public function testGetRule()
    {
        $domain = \uniqid() . '-get.custom.localhost';
        $ruleId = $this->setupAPIRule($domain);

        $this->assertNotEmpty($ruleId);

        $rule = $this->getRule($ruleId);
        $this->assertEquals(200, $rule['headers']['status-code']);
        $this->assertEquals($domain, $rule['body']['domain']);
        $this->assertEquals('manual', $rule['body']['trigger']);
        $this->assertArrayHasKey('$id', $rule['body']);
        $this->assertArrayHasKey('domain', $rule['body']);
        $this->assertArrayHasKey('type', $rule['body']);
        $this->assertArrayHasKey('redirectUrl', $rule['body']);
        $this->assertArrayHasKey('redirectStatusCode', $rule['body']);
        $this->assertArrayHasKey('deploymentResourceType', $rule['body']);
        $this->assertArrayHasKey('deploymentId', $rule['body']);
        $this->assertArrayHasKey('deploymentResourceId', $rule['body']);
        $this->assertArrayHasKey('deploymentVcsProviderBranch', $rule['body']);
        $this->assertArrayHasKey('logs', $rule['body']);
        $this->assertArrayHasKey('renewAt', $rule['body']);

        $this->cleanupRule($ruleId);
    }

    public function testListRules()
    {
        $rules = $this->listRules();
        $this->assertEquals(200, $rules['headers']['status-code']);
        foreach ($rules['body']['rules'] as $rule) {
            $rule = $this->deleteRule($rule['$id']);
            $this->assertEquals(204, $rule['headers']['status-code']);
        }

        $rules = $this->listRules();
        $this->assertEquals(200, $rules['headers']['status-code']);
        $this->assertEquals(0, $rules['body']['total']);
        $this->assertCount(0, $rules['body']['rules']);

        $rule1Domain = \uniqid() . '-list1.custom.localhost';
        $rule1Id = $this->setupAPIRule($rule1Domain);
        $this->assertNotEmpty($rule1Id);

        $rules = $this->listRules();
        $this->assertEquals(200, $rules['headers']['status-code']);
        $this->assertEquals(1, $rules['body']['total']);
        $this->assertCount(1, $rules['body']['rules']);
        $this->assertEquals($rule1Domain, $rules['body']['rules'][0]['domain']);

        $this->assertEquals('manual', $rules['body']['rules'][0]['trigger']);
        $this->assertArrayHasKey('$id', $rules['body']['rules'][0]);
        $this->assertArrayHasKey('domain', $rules['body']['rules'][0]);
        $this->assertArrayHasKey('type', $rules['body']['rules'][0]);
        $this->assertArrayHasKey('redirectUrl', $rules['body']['rules'][0]);
        $this->assertArrayHasKey('redirectStatusCode', $rules['body']['rules'][0]);
        $this->assertArrayHasKey('deploymentResourceType', $rules['body']['rules'][0]);
        $this->assertArrayHasKey('deploymentId', $rules['body']['rules'][0]);
        $this->assertArrayHasKey('deploymentResourceId', $rules['body']['rules'][0]);
        $this->assertArrayHasKey('deploymentVcsProviderBranch', $rules['body']['rules'][0]);
        $this->assertArrayHasKey('logs', $rules['body']['rules'][0]);
        $this->assertArrayHasKey('renewAt', $rules['body']['rules'][0]);

        $rule2Domain = \uniqid() . '-list1.custom.localhost';
        $rule2Id = $this->setupAPIRule($rule2Domain);
        $this->assertNotEmpty($rule2Id);

        $rules = $this->listRules();
        $this->assertEquals(200, $rules['headers']['status-code']);
        $this->assertEquals(2, $rules['body']['total']);
        $this->assertCount(2, $rules['body']['rules']);

        $rules = $this->listRules([
            'queries' => [
                Query::limit(1)->toString()
            ]
        ]);
        $this->assertEquals(200, $rules['headers']['status-code']);
        $this->assertEquals(2, $rules['body']['total']);
        $this->assertCount(1, $rules['body']['rules']);

        $rules = $this->listRules([
            'queries' => [
                Query::equal('$id', [$rule1Id])->toString()
            ]
        ]);
        $this->assertEquals(200, $rules['headers']['status-code']);
        $this->assertCount(1, $rules['body']['rules']);
        $this->assertEquals($rule1Domain, $rules['body']['rules'][0]['domain']);

        $rules = $this->listRules([
            'queries' => [
                Query::orderDesc('$id')->toString()
            ]
        ]);
        $this->assertEquals(200, $rules['headers']['status-code']);
        $this->assertCount(2, $rules['body']['rules']);
        $this->assertEquals($rule2Id, $rules['body']['rules'][0]['$id']);

        $rules = $this->listRules([
            'queries' => [
                Query::equal('domain', [$rule2Domain])->toString()
            ]
        ]);
        $this->assertEquals(200, $rules['headers']['status-code']);
        $this->assertCount(1, $rules['body']['rules']);
        $this->assertEquals($rule2Id, $rules['body']['rules'][0]['$id']);

        $rules = $this->listRules([
            'search' => $rule1Domain,
            'queries' => [ Query::orderDesc('$createdAt')->toString() ]
        ]);

        $this->assertEquals(200, $rules['headers']['status-code']);
        $ruleIds = \array_column($rules['body']['rules'], '$id');
        $this->assertContains($rule1Id, $ruleIds);

        $rules = $this->listRules([
            'search' => $rule2Domain,
            'queries' => [ Query::orderDesc('$createdAt')->toString() ]
        ]);
        $this->assertEquals(200, $rules['headers']['status-code']);
        $ruleIds = \array_column($rules['body']['rules'], '$id');
        $this->assertContains($rule2Id, $ruleIds);

        $rules = $this->listRules([
            'search' => $rule1Id,
            'queries' => [ Query::orderDesc('$createdAt')->toString() ]
        ]);
        $this->assertEquals(200, $rules['headers']['status-code']);
        $ruleDomains = \array_column($rules['body']['rules'], 'domain');
        $this->assertContains($rule1Domain, $ruleDomains);

        $rules = $this->listRules([
            'search' => $rule2Id,
            'queries' => [ Query::orderDesc('$createdAt')->toString() ]
        ]);
        $this->assertEquals(200, $rules['headers']['status-code']);
        $ruleDomains = \array_column($rules['body']['rules'], 'domain');
        $this->assertContains($rule2Domain, $ruleDomains);

        $rules = $this->listRules([
            'queries' => [
                Query::search('domain', $rule1Domain)->toString(),
            ],
        ]);
        $this->assertEquals(400, $rules['headers']['status-code']);
        $this->assertSame('general_query_invalid', $rules['body']['type']);

        $rules = $this->listRules();
        $this->assertEquals(200, $rules['headers']['status-code']);
        foreach ($rules['body']['rules'] as $rule) {
            $rule = $this->deleteRule($rule['$id']);
            $this->assertEquals(204, $rule['headers']['status-code']);
        }

        $rules = $this->listRules();
        $this->assertEquals(200, $rules['headers']['status-code']);
        $this->assertEquals(0, $rules['body']['total']);
        $this->assertCount(0, $rules['body']['rules']);
    }

    public function testRuleVerification(): void
    {
        $fixture = \uniqid($this->getSide() . '-');

        // 1. Site rule can verify
        $site = $this->setupSite();
        $siteId = $site['siteId'];

        $rule = $this->createSiteRule("{$fixture}.stage-site.webapp.com", $siteId);
        $this->assertEquals(201, $rule['headers']['status-code']);
        $this->assertEquals('verifying', $rule['body']['status']);
        $this->assertEmpty($rule['body']['logs']);
        $this->assertNotEmpty($rule['body']['$id']);
        $ruleId = $rule['body']['$id'];

        $rule = $this->updateRuleStatus($ruleId);
        $this->assertEquals(200, $rule['headers']['status-code']);
        $this->assertEquals($ruleId, $rule['body']['$id']);
        $this->assertEquals('verifying', $rule['body']['status']);
        $this->assertEmpty($rule['body']['logs']);

        $this->cleanupRule($rule['body']['$id']);
        $this->cleanupSite($siteId);

        // 2. Function rule can verify
        $function = $this->setupFunction();
        $functionId = $function['functionId'];

        $rule = $this->createFunctionRule("{$fixture}.stage-function.webapp.com", $functionId);
        $this->assertEquals(201, $rule['headers']['status-code']);
        $this->assertEquals('verifying', $rule['body']['status']);
        $this->assertEmpty($rule['body']['logs']);
        $this->cleanupRule($rule['body']['$id']);

        $rule = $this->createAPIRule("{$fixture}.stage-site.webapp.com");
        $this->assertEquals(201, $rule['headers']['status-code']);
        $this->assertEquals('unverified', $rule['body']['status']);
        $this->assertStringContainsString('has incorrect CNAME value', $rule['body']['logs']);
        $this->cleanupRule($rule['body']['$id']);

        $this->cleanupFunction($functionId);

        // 3. Wrong A record fails to verify
        $rule = $this->createAPIRule("{$fixture}.wrong-a-webapp.com");
        $this->assertEquals(201, $rule['headers']['status-code']);
        $this->assertEquals('unverified', $rule['body']['status']);
        $this->assertStringContainsString('is missing CNAME record', $rule['body']['logs']);

        $ruleId = $rule['body']['$id'];
        $rule = $this->updateRuleStatus($ruleId);
        $this->assertEquals(400, $rule['headers']['status-code']);
        $this->assertStringContainsString('is missing CNAME record', $rule['body']['message']);

        $rule = $this->getRule($ruleId);
        $this->assertEquals(200, $rule['headers']['status-code']);
        $this->assertEquals('unverified', $rule['body']['status']);

        $this->cleanupRule($ruleId);

        // 4. Correct A record can verify
        $rule = $this->createAPIRule("{$fixture}.correct-a.webapp.com");
        $this->assertEquals(201, $rule['headers']['status-code']);
        $this->assertEquals('verifying', $rule['body']['status']);
        $this->assertEmpty($rule['body']['logs']);

        $this->cleanupRule($rule['body']['$id']);

        // 5. Correct CNAME record can verify (no CAA record)
        $rule = $this->createAPIRule("{$fixture}.stage.webapp.com");
        $this->assertEquals(201, $rule['headers']['status-code']);
        $this->assertEquals('verifying', $rule['body']['status']);
        $this->assertEmpty($rule['body']['logs']);

        $this->cleanupRule($rule['body']['$id']);

        // 6. Missing CNAME record fails to verify
        $rule = $this->createAPIRule("{$fixture}.stage-missing-cname.webapp.com");
        $this->assertEquals(201, $rule['headers']['status-code']);
        $this->assertEquals('unverified', $rule['body']['status']);
        $this->assertStringContainsString('is missing CNAME record', $rule['body']['logs']);

        $ruleId = $rule['body']['$id'];
        $rule = $this->updateRuleStatus($ruleId);
        $this->assertEquals(400, $rule['headers']['status-code']);
        $this->assertStringContainsString('is missing CNAME record', $rule['body']['message']);

        $rule = $this->getRule($ruleId);
        $this->assertEquals(200, $rule['headers']['status-code']);
        $this->assertEquals('unverified', $rule['body']['status']);

        $this->cleanupRule($ruleId);

        // 7. Wrong CNAME record fails to verify
        $rule = $this->createAPIRule("{$fixture}.stage-wrong-cname.webapp.com");
        $this->assertEquals(201, $rule['headers']['status-code']);
        $this->assertEquals('unverified', $rule['body']['status']);
        $this->assertStringContainsString('has incorrect CNAME value', $rule['body']['logs']);

        $ruleId = $rule['body']['$id'];
        $rule = $this->updateRuleStatus($ruleId);
        $this->assertEquals(400, $rule['headers']['status-code']);
        $this->assertStringContainsString('has incorrect CNAME value', $rule['body']['message']);

        $rule = $this->getRule($ruleId);
        $this->assertEquals(200, $rule['headers']['status-code']);
        $this->assertEquals('unverified', $rule['body']['status']);

        $this->cleanupRule($ruleId);

        // 8. Wrong CAA record fails to verify
        $rule = $this->createAPIRule("{$fixture}.stage-wrong-caa.webapp.com");
        $this->assertEquals(201, $rule['headers']['status-code']);
        $this->assertEquals('unverified', $rule['body']['status']);
        $this->assertStringContainsString('has incorrect CAA value', $rule['body']['logs']);

        $ruleId = $rule['body']['$id'];
        $rule = $this->updateRuleStatus($ruleId);
        $this->assertEquals(400, $rule['headers']['status-code']);
        $this->assertStringContainsString('has incorrect CAA value', $rule['body']['message']);

        $rule = $this->getRule($ruleId);
        $this->assertEquals(200, $rule['headers']['status-code']);
        $this->assertEquals('unverified', $rule['body']['status']);

        $this->cleanupRule($ruleId);

        // 9. Correct CAA record can verify
        $rule = $this->createAPIRule("{$fixture}.stage-correct-caa.webapp.com");
        $this->assertEquals(201, $rule['headers']['status-code']);
        $this->assertEquals('verifying', $rule['body']['status']);
        $this->assertEmpty($rule['body']['logs']);

        $this->cleanupRule($rule['body']['$id']);
    }

    public function testUpdateRuleVerificationWithSameDataUpdatesTimestamp(): void
    {
        $domain = \uniqid() . '-timestamp-test.webapp.com';
        $rule = $this->createAPIRule($domain);

        $this->assertEquals(201, $rule['headers']['status-code']);
        $this->assertEquals('unverified', $rule['body']['status']);
        $this->assertNotEmpty($rule['body']['logs']);

        $ruleId = $rule['body']['$id'];
        $initialUpdatedAt = $rule['body']['$updatedAt'];
        $initiallogs = $rule['body']['logs'];

        sleep(1);

        $updatedRule = $this->updateRuleStatus($ruleId);

        $this->assertEquals(400, $updatedRule['headers']['status-code']);
        $this->assertStringContainsString($initiallogs, $updatedRule['body']['message']);

        $ruleAfterUpdate = $this->getRule($ruleId);
        $this->assertEquals(200, $ruleAfterUpdate['headers']['status-code']);
        $this->assertEquals('unverified', $ruleAfterUpdate['body']['status']);
        $this->assertEquals($initiallogs, $ruleAfterUpdate['body']['logs']);
        $this->assertNotEquals($initialUpdatedAt, $ruleAfterUpdate['body']['$updatedAt']);

        $initialTime = new \DateTime($initialUpdatedAt);
        $updatedTime = new \DateTime($ruleAfterUpdate['body']['$updatedAt']);
        $this->assertGreaterThan($initialTime, $updatedTime);

        $this->cleanupRule($ruleId);
    }
}
