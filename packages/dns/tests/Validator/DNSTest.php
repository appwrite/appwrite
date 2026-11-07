<?php

declare(strict_types=1);

namespace Utopia\DNS\Tests\Validator;

use PHPUnit\Framework\TestCase;
use Utopia\DNS\Message\Record;
use Utopia\DNS\Validator\DNS;

final class DNSTest extends TestCase
{
    public function testQueryFailureNamesTypeAndResolver(): void
    {
        // Nothing listens on loopback port 53, so the query fails without a network
        $validator = new DNS('appwrite.io', Record::TYPE_CNAME, '127.0.0.1');

        $this->assertFalse($validator->isValid('example.com'));
        $this->assertSame('DNS CNAME query to 127.0.0.1 timed out or failed.', $validator->getDescription());
    }
}
