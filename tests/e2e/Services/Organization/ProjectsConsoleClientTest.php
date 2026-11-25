<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Organization;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectConsole;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideConsole;

final class ProjectsConsoleClientTest extends Scope
{
    use ProjectsBase;
    use ProjectConsole;
    use SideConsole;

    /**
     * A GraphQL field acts on the organization its own route names, as the same REST call does,
     * not the organization of the outer /v1/graphql request, which has none.
     */
    public function testGraphQLListsTheSameProjectsAsREST(): void
    {
        $projectId = $this->setupOrganizationProject()['projectId'];
        $headers = array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getOrganizationHeaders());

        $rest = $this->client->call(Client::METHOD_GET, '/organization/projects', $headers);

        $this->assertSame(200, $rest['headers']['status-code']);
        $this->assertContains($projectId, \array_column($rest['body']['projects'], '$id'));

        $graphql = $this->client->call(Client::METHOD_POST, '/graphql', $headers, [
            'query' => 'query { organizationListProjects { total projects { _id } } }',
            'variables' => [],
        ]);

        $this->assertSame(200, $graphql['headers']['status-code']);
        $this->assertArrayNotHasKey('errors', $graphql['body'], \json_encode($graphql['body']['errors'] ?? []));
        $list = $graphql['body']['data']['organizationListProjects'];
        $this->assertSame($rest['body']['total'], $list['total']);
        $this->assertEqualsCanonicalizing(
            \array_column($rest['body']['projects'], '$id'),
            \array_column($list['projects'], '_id'),
        );
    }
}
