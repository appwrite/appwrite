<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Response\Model;

use Appwrite\Utopia\Response\Model\DistributionSigningIdentity;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;

final class DistributionSigningIdentityTest extends TestCase
{
    public function testSecretIsRemoved(): void
    {
        $identity = (new DistributionSigningIdentity())->filter(new Document([
            'platform' => 'android',
            'secret' => ['keystore' => 'base64', 'storePassword' => 'password'],
            'fingerprint' => 'fingerprint',
        ]));

        $this->assertFalse($identity->isSet('secret'));
        $this->assertSame('fingerprint', $identity->getAttribute('fingerprint'));
    }
}
