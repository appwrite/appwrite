<?php

namespace Tests\E2E\Services\Sites;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;

class SitesCustomClientTest extends Scope
{
    use SitesBase;
    use ProjectCustom;
    use SideClient;

    public function testListTemplates()
    {
        /**
         * Test for SUCCESS
         */
        // List all templates
        $templates = $this->client->call(Client::METHOD_GET, '/sites/templates', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $templates['headers']['status-code']);
        $this->assertGreaterThan(0, $templates['body']['total']);
        $this->assertIsArray($templates['body']['templates']);

        foreach ($templates['body']['templates'] as $template) {
            $this->assertArrayHasKey('name', $template);
            $this->assertArrayHasKey('key', $template);
            $this->assertArrayHasKey('useCases', $template);
            $this->assertArrayHasKey('vcsProvider', $template);
            $this->assertArrayHasKey('frameworks', $template);
            $this->assertArrayHasKey('variables', $template);
            $this->assertArrayHasKey('screenshotDark', $template);
            $this->assertArrayHasKey('screenshotLight', $template);
            $this->assertArrayHasKey('tagline', $template);
        }

        // List templates with pagination
        $templatesOffset = $this->client->call(Client::METHOD_GET, '/sites/templates', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'limit' => 1,
            'offset' => 2
        ]);
        $this->assertEquals(200, $templatesOffset['headers']['status-code']);
        $this->assertCount(1, $templatesOffset['body']['templates']);
        $this->assertEquals($templates['body']['templates'][2]['key'], $templatesOffset['body']['templates'][0]['key']);

        // List templates with filters
        $templates = $this->client->call(Client::METHOD_GET, '/sites/templates', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'useCases' => ['starter'],
            'frameworks' => ['nuxt']
        ]);
        $this->assertEquals(200, $templates['headers']['status-code']);
        $this->assertGreaterThan(0, $templates['body']['total']);
        $this->assertIsArray($templates['body']['templates']);
        foreach ($templates['body']['templates'] as $template) {
            $this->assertContains($template['useCases'][0], ['starter']);
        }
        $this->assertArrayHasKey('frameworks', $templates['body']['templates'][0]);
        $this->assertContains('Nuxt', array_column($templates['body']['templates'][0]['frameworks'], 'name'));

        // List templates with pagination and filters
        $templates = $this->client->call(Client::METHOD_GET, '/sites/templates', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'limit' => 5,
            'offset' => 0,
            'useCases' => ['starter'],
            'frameworks' => ['nextjs']
        ]);

        $this->assertEquals(200, $templates['headers']['status-code']);
        $this->assertGreaterThan(0, $templates['body']['total']);
        $this->assertIsArray($templates['body']['templates']);
        $this->assertArrayHasKey('frameworks', $templates['body']['templates'][0]);

        foreach ($templates['body']['templates'] as $template) {
            $this->assertContains($template['useCases'][0], ['starter']);
        }

        $this->assertContains('Next.js', array_column($templates['body']['templates'][0]['frameworks'], 'name'));

        /**
         * Test for FAILURE
         */
        // List templates with invalid limit
        $templates = $this->client->call(Client::METHOD_GET, '/sites/templates', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'limit' => 5001,
            'offset' => 10,
        ]);
        $this->assertEquals(400, $templates['headers']['status-code']);

        // List templates with invalid offset
        $templates = $this->client->call(Client::METHOD_GET, '/sites/templates', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'limit' => 5,
            'offset' => 5001,
        ]);
        $this->assertEquals(400, $templates['headers']['status-code']);
    }

    public function testGetTemplate()
    {
        /**
         * Test for SUCCESS
         */
        $template = $this->getTemplate('starter-for-react');
        $this->assertEquals(200, $template['headers']['status-code']);
        $this->assertIsArray($template['body']);
        $this->assertEquals('starter-for-react', $template['body']['key']);
        $this->assertEquals('React starter', $template['body']['name']);
        $this->assertEquals(['starter'], $template['body']['useCases']);
        $this->assertEquals('github', $template['body']['vcsProvider']);
        $this->assertEquals('Simple React application integrated with Appwrite SDK.', $template['body']['tagline']);
        $this->assertIsArray($template['body']['frameworks']);
        $this->assertStringContainsString('/images/sites/templates/starter-for-react-dark.png', $template['body']['screenshotDark']);
        $this->assertStringContainsString('/images/sites/templates/starter-for-react-light.png', $template['body']['screenshotLight']);

        /**
         * Test for FAILURE
         */
        $template = $this->getTemplate('invalid-template-id');
        $this->assertEquals(404, $template['headers']['status-code']);
    }
}
