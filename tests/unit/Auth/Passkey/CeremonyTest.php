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

    /**
     * @param array<string, mixed> $auths
     */
    private function project(array $auths): Document
    {
        return new Document([
            '$id' => 'project',
            'name' => 'Project',
            'auths' => $auths,
            'platforms' => [
                ['type' => 'web', 'hostname' => 'app.example.com'],
                ['type' => 'web', 'hostname' => 'localhost'],
            ],
        ]);
    }
}
