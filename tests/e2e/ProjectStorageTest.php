<?php

namespace Tests\E2E;

use CURLFile;
use Tests\E2E\Client;

class ProjectStorafeTest extends BaseProjects
{
    public function testRegisterSuccess(): array
    {
        return $this->initProject(['files.read', 'files.write']);
    }

    /**
     * @depends testRegisterSuccess
     */
    public function testFileCreateSuccess(array $data): array
    {
        $file = $this->client->call(Client::METHOD_POST, '/storage/files', [
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $data['projectUid'],
            'x-appwrite-key' => $data['projectAPIKeySecret'],
        ], [
            'files' => new CURLFile(realpath(__DIR__ . '/../resources/logo.png'), 'image/png', 'logo.png'),
            'read' => ['*'],
            'write' => ['*'],
            'folderId' => 'xyz',
        ]);

        $this->assertNotEmpty($file['body'][0]['$uid']);
        $this->assertEquals('files', $file['body'][0]['$collection']);
        $this->assertIsInt($file['body'][0]['dateCreated']);
        $this->assertEquals('logo.png', $file['body'][0]['name']);
        $this->assertEquals('image/png', $file['body'][0]['mimeType']);
        $this->assertEquals(47218, $file['body'][0]['sizeOriginal']);

        return $data;
    }
}
