<?php

namespace Tests\E2E\Services\Proxy;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Query;
use Utopia\System\System;

class ProxyCustomServerTest extends Scope
{
    use ProxyBase;
    use ProjectCustom;
    use SideServer;

    protected function tearDown(): void
    {
        // Cleanup for testRuleVerification test
        // Required as it uses static domain name
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
            $this->assertEquals(0, count($rules['body']['rules']));
            $this->assertEquals(0, $rules['body']['total']);
        }
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
        $this->assertEquals('created', $rule['body']['status']);
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

        $domain =  \uniqid() . '-vcs.' . System::getEnv('_APP_DOMAIN_SITES', '');

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

    public function testCreateRedirectRule(): void
    {
        $domain = \uniqid() . '-redirect.custom.localhost';

        $proxyClient = new Client();
        $proxyClient->setEndpoint('http://appwrite.test');
        $proxyClient->addHeader('x-appwrite-hostname', $domain);

        $response = $proxyClient->call(Client::METHOD_GET, '/todos/1');
        $this->assertEquals(401, $response['headers']['status-code']);

        $siteId = $this->setupSite()['siteId'];

        $ruleId = $this->setupRedirectRule($domain, 'https://jsonplaceholder.typicode.com/todos/1', 301, 'site', $siteId);
        $this->assertNotEmpty($ruleId);

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
        $ruleId = $this->setupRedirectRule($domain, 'https://jsonplaceholder.typicode.com/todos/1', 307, 'site', $siteId);
        $this->assertNotEmpty($ruleId);

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

        $this->cleanupSite($siteId);
        $this->cleanupRule($ruleId);
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

        $response = $proxyClient->call(Client::METHOD_GET, '/contact');
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertStringContainsString('Contact page', $response['body']);

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
        $domain = \uniqid() . '-cname-api.' . System::getEnv('_APP_DOMAIN_FUNCTIONS');

        $rule = $this->createAPIRule($domain);
        $this->assertEquals(201, $rule['headers']['status-code']);
        $this->assertEquals('verified', $rule['body']['status']);

        $this->cleanupRule($rule['body']['$id']);

        // Create site appwrite-network domain
        $domain = \uniqid() . '-cname-api.' . System::getEnv('_APP_DOMAIN_SITES');

        $rule = $this->createAPIRule($domain);
        $this->assertEquals(201, $rule['headers']['status-code']);
        $this->assertEquals('verified', $rule['body']['status']);

        $this->cleanupRule($rule['body']['$id']);

        // Create + update
        $domain = \uniqid() . '-cname-api.custom.com';

        $rule = $this->createAPIRule($domain);
        $this->assertEquals(201, $rule['headers']['status-code']);
        $this->assertEquals('created', $rule['body']['status']);

        $ruleId = $rule['body']['$id'];

        $rule = $this->updateRuleVerification($ruleId);
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
            'queries' => [ Query::orderDesc('$createdAt') ]
        ]);

        $this->assertEquals(200, $rules['headers']['status-code']);
        $ruleIds = \array_column($rules['body']['rules'], '$id');
        $this->assertContains($rule1Id, $ruleIds);

        $rules = $this->listRules([
            'search' => $rule2Domain,
            'queries' => [ Query::orderDesc('$createdAt') ]
        ]);
        $this->assertEquals(200, $rules['headers']['status-code']);
        $ruleIds = \array_column($rules['body']['rules'], '$id');
        $this->assertContains($rule2Id, $ruleIds);

        $rules = $this->listRules([
            'search' => $rule1Id,
            'queries' => [ Query::orderDesc('$createdAt') ]
        ]);
        $this->assertEquals(200, $rules['headers']['status-code']);
        $ruleDomains = \array_column($rules['body']['rules'], 'domain');
        $this->assertContains($rule1Domain, $ruleDomains);

        $rules = $this->listRules([
            'search' => $rule2Id,
            'queries' => [ Query::orderDesc('$createdAt') ]
        ]);
        $this->assertEquals(200, $rules['headers']['status-code']);
        $ruleDomains = \array_column($rules['body']['rules'], 'domain');
        $this->assertContains($rule2Domain, $ruleDomains);

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

        // 1. Site rule can verify
        $site = $this->setupSite();
        $siteId = $site['siteId'];

        $rule = $this->createSiteRule('stage-site.webapp.com', $siteId);
        $this->assertEquals(201, $rule['headers']['status-code']);
        $this->assertEquals('verifying', $rule['body']['status']);
        $this->assertEmpty($rule['body']['logs']);
        $this->assertNotEmpty($rule['body']['$id']);
        $ruleId = $rule['body']['$id'];

        $rule = $this->updateRuleVerification($ruleId);
        $this->assertEquals(200, $rule['headers']['status-code']);
        $this->assertEquals($ruleId, $rule['body']['$id']);
        $this->assertEquals('verifying', $rule['body']['status']);
        $this->assertEmpty($rule['body']['logs']);

        $this->cleanupRule($rule['body']['$id']);
        $this->cleanupSite($siteId);

        // 2. Function rule can verify
        $function = $this->setupFunction();
        $functionId = $function['functionId'];

        $rule = $this->createFunctionRule('stage-function.webapp.com', $functionId);
        $this->assertEquals(201, $rule['headers']['status-code']);
        $this->assertEquals('verifying', $rule['body']['status']);
        $this->assertEmpty($rule['body']['logs']);
        $this->cleanupRule($rule['body']['$id']);

        $rule = $this->createAPIRule('stage-site.webapp.com');
        $this->assertEquals(201, $rule['headers']['status-code']);
        $this->assertEquals('created', $rule['body']['status']);
        $this->assertStringContainsString('has incorrect CNAME value', $rule['body']['logs']);
        $this->cleanupRule($rule['body']['$id']);

        $this->cleanupFunction($functionId);

        // 3. Wrong A record fails to verify
        $rule = $this->createAPIRule('wrong-a-webapp.com');
        $this->assertEquals(201, $rule['headers']['status-code']);
        $this->assertEquals('created', $rule['body']['status']);
        $this->assertStringContainsString('is missing CNAME record', $rule['body']['logs']);

        $ruleId = $rule['body']['$id'];
        $rule = $this->updateRuleVerification($ruleId);
        $this->assertEquals(400, $rule['headers']['status-code']);
        $this->assertStringContainsString('is missing CNAME record', $rule['body']['message']);

        $rule = $this->getRule($ruleId);
        $this->assertEquals(200, $rule['headers']['status-code']);
        $this->assertEquals('created', $rule['body']['status']);

        $this->cleanupRule($ruleId);

        // 4. Correct A record can verify
        $rule = $this->createAPIRule('webapp.com');
        $this->assertEquals(201, $rule['headers']['status-code']);
        $this->assertEquals('verifying', $rule['body']['status']);
        $this->assertEmpty($rule['body']['logs']);

        $this->cleanupRule($rule['body']['$id']);

        // 5. Correct CNAME record can verify (no CAA record)
        $rule = $this->createAPIRule('stage.webapp.com');
        $this->assertEquals(201, $rule['headers']['status-code']);
        $this->assertEquals('verifying', $rule['body']['status']);
        $this->assertEmpty($rule['body']['logs']);

        $this->cleanupRule($rule['body']['$id']);

        // 6. Missing CNAME record fails to verify
        $rule = $this->createAPIRule('stage-missing-cname.webapp.com');
        $this->assertEquals(201, $rule['headers']['status-code']);
        $this->assertEquals('created', $rule['body']['status']);
        $this->assertStringContainsString('is missing CNAME record', $rule['body']['logs']);

        $ruleId = $rule['body']['$id'];
        $rule = $this->updateRuleVerification($ruleId);
        $this->assertEquals(400, $rule['headers']['status-code']);
        $this->assertStringContainsString('is missing CNAME record', $rule['body']['message']);

        $rule = $this->getRule($ruleId);
        $this->assertEquals(200, $rule['headers']['status-code']);
        $this->assertEquals('created', $rule['body']['status']);

        $this->cleanupRule($ruleId);

        // 7. Wrong CNAME record fails to verify
        $rule = $this->createAPIRule('stage-wrong-cname.webapp.com');
        $this->assertEquals(201, $rule['headers']['status-code']);
        $this->assertEquals('created', $rule['body']['status']);
        $this->assertStringContainsString('has incorrect CNAME value', $rule['body']['logs']);

        $ruleId = $rule['body']['$id'];
        $rule = $this->updateRuleVerification($ruleId);
        $this->assertEquals(400, $rule['headers']['status-code']);
        $this->assertStringContainsString('has incorrect CNAME value', $rule['body']['message']);

        $rule = $this->getRule($ruleId);
        $this->assertEquals(200, $rule['headers']['status-code']);
        $this->assertEquals('created', $rule['body']['status']);

        $this->cleanupRule($ruleId);

        // 8. Wrong CAA record fails to verify
        $rule = $this->createAPIRule('stage-wrong-caa.webapp.com');
        $this->assertEquals(201, $rule['headers']['status-code']);
        $this->assertEquals('created', $rule['body']['status']);
        $this->assertStringContainsString('has incorrect CAA value', $rule['body']['logs']);

        $ruleId = $rule['body']['$id'];
        $rule = $this->updateRuleVerification($ruleId);
        $this->assertEquals(400, $rule['headers']['status-code']);
        $this->assertStringContainsString('has incorrect CAA value', $rule['body']['message']);

        $rule = $this->getRule($ruleId);
        $this->assertEquals(200, $rule['headers']['status-code']);
        $this->assertEquals('created', $rule['body']['status']);

        $this->cleanupRule($ruleId);

        // 9. Correct CAA record can verify
        $rule = $this->createAPIRule('stage-correct-caa.webapp.com');
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
        $this->assertEquals('created', $rule['body']['status']);
        $this->assertNotEmpty($rule['body']['logs']);

        $ruleId = $rule['body']['$id'];
        $initialUpdatedAt = $rule['body']['$updatedAt'];
        $initiallogs = $rule['body']['logs'];

        sleep(1);

        $updatedRule = $this->updateRuleVerification($ruleId);

        $this->assertEquals(400, $updatedRule['headers']['status-code']);
        $this->assertStringContainsString($initiallogs, $updatedRule['body']['message']);

        $ruleAfterUpdate = $this->getRule($ruleId);
        $this->assertEquals(200, $ruleAfterUpdate['headers']['status-code']);
        $this->assertEquals('created', $ruleAfterUpdate['body']['status']);
        $this->assertEquals($initiallogs, $ruleAfterUpdate['body']['logs']);
        $this->assertNotEquals($initialUpdatedAt, $ruleAfterUpdate['body']['$updatedAt']);

        $initialTime = new \DateTime($initialUpdatedAt);
        $updatedTime = new \DateTime($ruleAfterUpdate['body']['$updatedAt']);
        $this->assertGreaterThan($initialTime, $updatedTime);

        $this->cleanupRule($ruleId);
    }
}
