<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Avatars;

use Appwrite\Extend\Exception;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Id;
use Utopia\Database\Permission;
use Utopia\Database\Role;

final class AvatarsCustomServerTest extends Scope
{
    use AvatarsBase;
    use ProjectCustom;
    use SideServer;

    public function testGetFaviconUnsupportedRasterIcon(): void
    {
        $headers = \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders());

        $domain = 'avatars-favicon-' . \uniqid() . '.127.0.0.1.nip.io';

        $rule = $this->client->call(Client::METHOD_POST, '/proxy/rules/api', $headers, [
            'domain' => $domain,
        ]);

        $this->assertEquals(201, $rule['headers']['status-code']);

        $pageUrl = 'http://' . $domain . '/v1/mock/tests/avatars/favicon-page';

        $response = $this->client->call(Client::METHOD_GET, '/avatars/favicon', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => $pageUrl,
        ]);

        $this->assertEquals(404, $response['headers']['status-code']);
        $this->assertEquals(Exception::AVATAR_ICON_NOT_FOUND, $response['body']['type']);
    }

    public function testGetFaviconCorruptRasterIcon(): void
    {
        $headers = \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders());

        $domain = 'avatars-favicon-trunc-' . \uniqid() . '.127.0.0.1.nip.io';

        $rule = $this->client->call(Client::METHOD_POST, '/proxy/rules/api', $headers, [
            'domain' => $domain,
        ]);

        $this->assertEquals(201, $rule['headers']['status-code']);

        $pageUrl = 'http://' . $domain . '/v1/mock/tests/avatars/favicon-page-truncated';

        $response = $this->client->call(Client::METHOD_GET, '/avatars/favicon', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'url' => $pageUrl,
        ]);

        $this->assertEquals(404, $response['headers']['status-code']);
        $this->assertEquals(Exception::AVATAR_ICON_NOT_FOUND, $response['body']['type']);
    }
}
