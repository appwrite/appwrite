<?php

namespace Tests\E2E\Services\Storage;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Validator\Datetime as DatetimeValidator;

class StorageAPKMimeTypeTest extends Scope
{
    use StorageBase;
    use ProjectCustom;
    use SideServer;

    /**
     * Test that APK files are stored and served with correct MIME type
     * This testaddresses GitHub issue #9418 where APK files were incorrectly
     * detected as application/zip instead of application/vnd.android.package-archive
     *
     * @return array
     */
    public function testAPKFileMimeType(): array
    {
        // Create a test bucket
        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'bucketId' => ID::unique(),
            'name' => 'APK Test Bucket',
            'fileSecurity' => true,
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        $this->assertEquals(201, $bucket['headers']['status-code']);
        $this->assertNotEmpty($bucket['body']['$id']);
        $bucketId = $bucket['body']['$id'];

        // Create a minimal valid APK file using shell commands
        $tmpDir = sys_get_temp_dir() . '/apktest_' . uniqid();
        mkdir($tmpDir);

        // APK files must contain at least AndroidManifest.xml to be valid
        $manifestContent = '<?xml version="1.0" encoding="utf-8"?>
<manifest xmlns:android="http://schemas.android.com/apk/res/android"
    package="com.test.apktest">
    <application android:label="Test APK" />
</manifest>';

        file_put_contents($tmpDir . '/AndroidManifest.xml', $manifestContent);
        file_put_contents($tmpDir . '/classes.dex', 'dex\n035\x00');

        // Create META-INF directory
        mkdir($tmpDir . '/META-INF');
        file_put_contents($tmpDir . '/META-INF/MANIFEST.MF', 'Manifest-Version: 1.0');

        // Create APK file using shell command
        $apkFile = sys_get_temp_dir() . '/test-app_' . uniqid() . '.apk';
        $currentDir = getcwd();
        chdir($tmpDir);
        exec('zip -r ' . escapeshellarg($apkFile) . ' . 2>&1', $output, $returnCode);
        chdir($currentDir);

        $this->assertEquals(0, $returnCode, 'Failed to create APK file: ' . implode("\n", $output));
        $this->assertFileExists($apkFile, 'APK file was not created');

        try {
            // Upload the APK file
            $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', array_merge([
                'content-type' => 'multipart/form-data',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()), [
                'fileId' => ID::unique(),
                'file' => new \CURLFile($apkFile, 'application/vnd.android.package-archive', 'test-app.apk'),
                'permissions' => [
                    Permission::read(Role::any()),
                    Permission::update(Role::any()),
                    Permission::delete(Role::any()),
                ],
            ]);

            // Verify upload was successful
            $this->assertEquals(201, $file['headers']['status-code'], 'APK file upload failed');
            $this->assertNotEmpty($file['body']['$id']);
            $this->assertEquals('test-app.apk', $file['body']['name']);

            // CRITICAL: Verify MIME type is correctly set to APK type, not ZIP
            $this->assertEquals(
                'application/vnd.android.package-archive',
                $file['body']['mimeType'],
                'APK file MIME type should be application/vnd.android.package-archive, not application/zip'
            );

            $dateValidator = new DatetimeValidator();
            $this->assertTrue($dateValidator->isValid($file['body']['$createdAt']));

            $fileId = $file['body']['$id'];

            // Test the download endpoint returns correct Content-Type header
            $downloadResponse = $this->client->call(
                Client::METHOD_GET,
                '/storage/buckets/' . $bucketId . '/files/' . $fileId . '/download',
                array_merge([
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id'],
                ], $this->getHeaders())
            );

            $this->assertEquals(200, $downloadResponse['headers']['status-code']);
            $this->assertStringContainsString(
                'application/vnd.android.package-archive',
                $downloadResponse['headers']['content-type'],
                'Download endpoint should return APK MIME type in Content-Type header'
            );
            $this->assertEquals(
                'attachment; filename="test-app.apk"',
                $downloadResponse['headers']['content-disposition'],
                'Content-Disposition should preserve .apk filename'
            );

            // Test the view endpoint also returns correct Content-Type
            // Note: APK files may not be in the allowed MIME types for viewing,
            // but we should still verify the MIME type handling
            $viewResponse = $this->client->call(
                Client::METHOD_GET,
                '/storage/buckets/' . $bucketId . '/files/' . $fileId . '/view',
                array_merge([
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id'],
                ], $this->getHeaders())
            );

            // View endpoint may return text/plain for unsupported MIME types, but check that
            // the file still has the correct MIME type stored
            $this->assertEquals(200, $viewResponse['headers']['status-code']);

            // Get the file metadata to verify MIME type persists
            $fileInfo = $this->client->call(
                Client::METHOD_GET,
                '/storage/buckets/' . $bucketId . '/files/' . $fileId,
                array_merge([
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id'],
                ], $this->getHeaders())
            );

            $this->assertEquals(200, $fileInfo['headers']['status-code']);
            $this->assertEquals(
                'application/vnd.android.package-archive',
                $fileInfo['body']['mimeType'],
                'File metadata should persistently store APK MIME type'
            );

            return [
                'bucketId' => $bucketId,
                'fileId' => $fileId,
            ];
        } finally {
            // Cleanup: remove temporary files
            if (file_exists($apkFile)) {
                unlink($apkFile);
            }
            if (isset($tmpDir) && is_dir($tmpDir)) {
                exec('rm -rf ' . escapeshellarg($tmpDir));
            }
        }
    }

    /**
     * Test that XAPK files (Android Package Bundle) also get correct MIME  type
     *
     * @return void
     */
    public function testXAPKFileMimeType(): void
    {
        // Create a test bucket
        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'bucketId' => ID::unique(),
            'name' => 'XAPK Test Bucket',
            'fileSecurity' => true,
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
            ],
        ]);

        $this->assertEquals(201, $bucket['headers']['status-code']);
        $bucketId = $bucket['body']['$id'];

        // Create a minimal XAPK file using shell commands
        $tmpDir = sys_get_temp_dir() . '/xapktest_' . uniqid();
        mkdir($tmpDir);
        file_put_contents($tmpDir . '/manifest.json', '{"package_name":"com.test.xapk"}');

        $xapkFile = sys_get_temp_dir() . '/test-app_' . uniqid() . '.xapk';
        $currentDir = getcwd();
        chdir($tmpDir);
        exec('zip -r ' . escapeshellarg($xapkFile) . ' . 2>&1', $output, $returnCode);
        chdir($currentDir);

        try {
            $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', array_merge([
                'content-type' => 'multipart/form-data',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()), [
                'fileId' => ID::unique(),
                'file' => new \CURLFile($xapkFile, 'application/vnd.android.package-archive', 'test-app.xapk'),
                'permissions' => [
                    Permission::read(Role::any()),
                ],
            ]);

            $this->assertEquals(201, $file['headers']['status-code']);
            $this->assertEquals('test-app.xapk', $file['body']['name']);
            $this->assertEquals(
                'application/vnd.android.package-archive',
                $file['body']['mimeType'],
                'XAPK file should also get APK MIME type'
            );
        } finally {
            if (file_exists($xapkFile)) {
                unlink($xapkFile);
            }
            if (isset($tmpDir) && is_dir($tmpDir)) {
                exec('rm -rf ' . escapeshellarg($tmpDir));
            }
        }
    }

    /**
     * Test that regular ZIP files still get application/zip MIME type
     * This ensures our fix doesn't break normal ZIP file handling
     *
     * @return void
     */
    public function testRegularZIPFileMimeType(): void
    {
        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'bucketId' => ID::unique(),
            'name' => 'ZIP Test Bucket',
            'fileSecurity' => true,
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
            ],
        ]);

        $this->assertEquals(201, $bucket['headers']['status-code']);
        $bucketId = $bucket['body']['$id'];

        // Create a regular ZIP file using shell commands
        $tmpDir = sys_get_temp_dir() . '/ziptest_' . uniqid();
        mkdir($tmpDir);
        file_put_contents($tmpDir . '/test.txt', 'This is a regular ZIP file');

        $zipFile = sys_get_temp_dir() . '/test_' . uniqid() . '.zip';
        $currentDir = getcwd();
        chdir($tmpDir);
        exec('zip -r ' . escapeshellarg($zipFile) . ' . 2>&1', $output, $returnCode);
        chdir($currentDir);

        try {
            $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', array_merge([
                'content-type' => 'multipart/form-data',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()), [
                'fileId' => ID::unique(),
                'file' => new \CURLFile($zipFile, 'application/zip', 'test.zip'),
                'permissions' => [
                    Permission::read(Role::any()),
                ],
            ]);

            $this->assertEquals(201, $file['headers']['status-code']);
            $this->assertEquals('test.zip', $file['body']['name']);
            $this->assertEquals(
                'application/zip',
                $file['body']['mimeType'],
                'Regular ZIP files should still have application/zip MIME type'
            );
        } finally {
            if (file_exists($zipFile)) {
                unlink($zipFile);
            }
            if (isset($tmpDir) && is_dir($tmpDir)) {
                exec('rm -rf ' . escapeshellarg($tmpDir));
            }
        }
    }

    /**
     * Test case-insensitive extension matching
     * APK files with uppercase extension should also work
     *
     * @return void
     */
    public function testAPKFileUppercaseExtension(): void
    {
        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'bucketId' => ID::unique(),
            'name' => 'APK Case Test Bucket',
            'fileSecurity' => true,
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
            ],
        ]);

        $this->assertEquals(201, $bucket['headers']['status-code']);
        $bucketId = $bucket['body']['$id'];

        $tmpDir = sys_get_temp_dir() . '/apktest_' . uniqid();
        mkdir($tmpDir);
        file_put_contents($tmpDir . '/AndroidManifest.xml', '<?xml version="1.0"?><manifest></manifest>');

        // Use uppercase .APK extension
        $apkFile = sys_get_temp_dir() . '/test-APP_' . uniqid() . '.APK';
        $currentDir = getcwd();
        chdir($tmpDir);
        exec('zip -r ' . escapeshellarg($apkFile) . ' . 2>&1', $output, $returnCode);
        chdir($currentDir);

        try {
            $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', array_merge([
                'content-type' => 'multipart/form-data',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()), [
                'fileId' => ID::unique(),
                'file' => new \CURLFile($apkFile, 'application/vnd.android.package-archive', 'test-APP.APK'),
                'permissions' => [
                    Permission::read(Role::any()),
                ],
            ]);

            $this->assertEquals(201, $file['headers']['status-code']);
            $this->assertEquals('test-APP.APK', $file['body']['name']);
            $this->assertEquals(
                'application/vnd.android.package-archive',
                $file['body']['mimeType'],
                'APK files with uppercase extension should also get correct MIME type'
            );
        } finally {
            if (file_exists($apkFile)) {
                unlink($apkFile);
            }
            if (isset($tmpDir) && is_dir($tmpDir)) {
                exec('rm -rf ' . escapeshellarg($tmpDir));
            }
        }
    }
}
