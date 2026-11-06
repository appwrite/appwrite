<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Response\Model;

use Appwrite\Utopia\Response\Model\Provider;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;

final class ProviderTest extends TestCase
{
    public function testLegacyBundleKeyIsShownAsBundleId(): void
    {
        $provider = (new Provider())->filter(new Document([
            'provider' => 'apns',
            'credentials' => [
                'teamId' => 'team-id',
                'bundleId' => 'io.appwrite.before',
                'bundle' => 'io.appwrite.after',
            ],
        ]));

        $this->assertSame([
            'teamId' => 'team-id',
            'bundleId' => 'io.appwrite.after',
        ], $provider->getAttribute('credentials'));
    }

    public function testCredentialsWithoutLegacyKeyAreUnchanged(): void
    {
        $credentials = ['teamId' => 'team-id', 'bundleId' => 'io.appwrite.app'];

        $provider = (new Provider())->filter(new Document([
            'provider' => 'apns',
            'credentials' => $credentials,
        ]));

        $this->assertSame($credentials, $provider->getAttribute('credentials'));
    }

    public function testEmptyCredentialsStayAnObject(): void
    {
        $provider = (new Provider())->filter(new Document([
            'provider' => 'appwrite',
            'credentials' => [],
        ]));

        $this->assertEquals(new \stdClass(), $provider->getAttribute('credentials'));
    }
}
