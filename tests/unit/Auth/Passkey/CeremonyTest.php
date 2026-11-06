<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\Passkey;

use Appwrite\Auth\Passkey\Ceremony;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;

final class CeremonyTest extends TestCase
{
    public function testConfiguredOriginsAreUsedAsTheyAre(): void
    {
        $project = $this->project(['passkeyRpId' => 'example.com', 'passkeyOrigins' => ['https://example.com']]);

        $ceremony = Ceremony::fromProject($project, 'http://localhost:5173');

        $this->assertInstanceOf(Ceremony::class, $ceremony);
        $this->assertSame('example.com', $ceremony->relyingParty->id);
        $this->assertSame(['https://example.com'], $ceremony->relyingParty->origins);
        $this->assertSame(['https://example.com'], Ceremony::getOrigins($project));
    }

    public function testOriginsComeFromPlatformsWithoutConfiguredOrigins(): void
    {
        $project = $this->project(['passkeyRpId' => 'example.com']);

        $this->assertSame('example.com', Ceremony::fromProject($project, 'https://app.example.com')?->relyingParty->id);
        $this->assertSame('localhost', Ceremony::fromProject($project, 'http://localhost:5173')?->relyingParty->id);
        $this->assertSame(['https://app.example.com', 'http://localhost', 'https://localhost'], Ceremony::getOrigins($project));
    }

    public function testAndroidAppsSignInWithTheirSigningCertificates(): void
    {
        $project = $this->project(['passkeyRpId' => 'example.com'], [
            ['type' => 'android', 'key' => 'com.example.app', 'fingerprints' => [
                '14:6D:E9:83:C5:73:06:50:D8:EE:B9:95:2F:34:FC:64:16:A0:83:42:E6:1D:BE:A8:8A:04:96:B2:3F:CF:44:E5',
                \implode(':', \array_fill(0, 32, 'FA')),
            ]],
            ['type' => 'react-native-android', 'key' => 'com.example.legacy', 'fingerprints' => [\implode(':', \array_fill(0, 32, '00'))]],
            ['type' => 'android', 'key' => 'com.example.unsigned'],
        ]);

        $this->assertSame([
            'android:apk-key-hash:FG3pg8VzBlDY7rmVLzT8ZBagg0LmHb6oigSWsj_PROU',
            'android:apk-key-hash:-vr6-vr6-vr6-vr6-vr6-vr6-vr6-vr6-vr6-vr6-vo',
            'android:apk-key-hash:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA',
            'https://app.example.com',
            'http://localhost',
            'https://localhost',
        ], Ceremony::getOrigins($project));

        // Apps cannot run on localhost, so local development stays web only
        $this->assertSame(['http://localhost', 'https://localhost'], Ceremony::fromProject($project, 'http://localhost:5173')?->relyingParty->origins);
    }

    /**
     * @param array<string, mixed> $auths
     * @param array<array<string, mixed>> $platforms
     */
    private function project(array $auths, array $platforms = []): Document
    {
        return new Document([
            '$id' => 'project',
            'name' => 'Project',
            'auths' => $auths,
            'platforms' => [
                ['type' => 'web', 'hostname' => 'app.example.com'],
                ['type' => 'web', 'hostname' => 'localhost'],
                ...$platforms,
            ],
        ]);
    }
}
