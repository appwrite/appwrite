<?php

namespace Tests\Unit\Network\Validators;

use Appwrite\Network\Validator\DNS;
use PHPUnit\Framework\TestCase;
use Utopia\DNS\Message\Record;

class DNSTest extends TestCase
{
    public function testSingleDNSServer(): void
    {
        $baseValidator = new DNS(['8.8.8.8']);
        $validator = $baseValidator->forRecord('appwrite.io', Record::TYPE_CNAME);

        $this->assertEquals(false, $validator->isValid(''));
        $this->assertEquals(false, $validator->isValid(null));
        $this->assertEquals('string', $validator->getType());
    }

    public function testMultipleDNSServers(): void
    {
        $baseValidator = new DNS(['8.8.8.8', '1.1.1.1']);
        $validator = $baseValidator->forRecord('appwrite.io', Record::TYPE_CNAME);

        $this->assertEquals(false, $validator->isValid(''));
        $this->assertEquals(false, $validator->isValid(null));
        $this->assertEquals('string', $validator->getType());
    }

    public function testValidationFailure(): void
    {
        $baseValidator = new DNS(['8.8.8.8', '1.1.1.1']);
        $validator = $baseValidator->forRecord('invalid-target.example.com', Record::TYPE_CNAME);

        $result = $validator->isValid('nonexistent-domain-' . \uniqid() . '.com');

        $this->assertEquals(false, $result);
        $this->assertIsInt($validator->count);
        $this->assertIsString($validator->value);
        $this->assertIsArray($validator->records);
        $this->assertIsString($validator->getDescription());
    }

    public function testCoreDNSFailure(): void
    {
        // CoreDNS is configured to return cname.localhost. for stage.webapp.com
        $baseValidator = new DNS(['172.16.238.100', '8.8.8.8']);
        $validator = $baseValidator->forRecord('cname.localhost.', Record::TYPE_CNAME);

        $result = $validator->isValid('stage.webapp.com');
        $this->assertEquals(false, $result);

        $result = $validator->isValid('stage-wrong-cname.webapp.com');
        $this->assertEquals(false, $result);
    }
}
