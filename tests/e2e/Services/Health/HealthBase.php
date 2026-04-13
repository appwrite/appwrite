<?php

namespace Tests\E2E\Services\Health;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;

abstract class HealthBase extends Scope
{
    use ProjectCustom;
    use SideServer;

    protected function getProjectId(): string
    {
        return $this->getProject()['$id'];
    }

    protected function callGet(string $path, array $query = []): array
    {
        return $this->client->call(Client::METHOD_GET, $path, \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProjectId(),
        ], $this->getHeaders()), $query);
    }
}
