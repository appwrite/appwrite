<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Databases;

use Appwrite\Extend\Exception;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ApiLegacy;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;

final class LegacyCustomServerTest extends Scope
{
    use DatabasesBase;
    use ProjectCustom;
    use SideServer;
    use ApiLegacy;

    public function testListDocumentsRejectsInvalidLegacyQueryType(): void
    {
        $response = $this->client->call(
            Client::METHOD_GET,
            $this->getRecordUrl('database', 'collection') . '?queries=invalid',
            array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-response-format' => '1.7.4',
            ], $this->getHeaders())
        );

        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame(Exception::GENERAL_ARGUMENT_INVALID, $response['body']['type']);
    }
}
