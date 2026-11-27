<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Response\Model;

use Appwrite\Utopia\Response\Model\DistributionConnection;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;

final class DistributionConnectionTest extends TestCase
{
    public function testCredentialsAreRemoved(): void
    {
        $connection = (new DistributionConnection())->filter(new Document([
            'provider' => 'apple',
            'credentials' => ['keyId' => 'ABC123', 'issuerId' => 'issuer', 'privateKey' => 'secret'],
            'account' => ['keyId' => '4XYZ'],
        ]));

        $this->assertFalse($connection->isSet('credentials'));
        $this->assertSame(['keyId' => '4XYZ'], $connection->getAttribute('account'));
    }
}
