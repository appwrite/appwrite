<?php

namespace Tests\Unit\Network\Validators;

use Appwrite\Network\Validator\DNS;
use PHPUnit\Framework\TestCase;
use Utopia\DNS\Message\Record;

class DNSTest extends TestCase
{
    private function runInCoroutine(callable $test): void
    {
        \Swoole\Coroutine\run($test);
    }

    public function testSingleDNSServer(): void
    {
        $this->runInCoroutine(function () {
            $validator = new DNS('appwrite.io', Record::TYPE_CNAME, ['8.8.8.8']);

            $this->assertFalse($validator->isValid(''));
            $this->assertFalse($validator->isValid(null));
            $this->assertEquals('string', $validator->getType());
        });
    }

    public function testMultipleDNSServers(): void
    {
        $this->runInCoroutine(function () {
            $validator = new DNS('appwrite.io', Record::TYPE_CNAME, ['8.8.8.8', '1.1.1.1']);

            $this->assertFalse($validator->isValid(''));
            $this->assertFalse($validator->isValid(null));
            $this->assertEquals('string', $validator->getType());
        });
    }

    public function testValidationFailure(): void
    {
        $this->runInCoroutine(function () {
            $validator = new DNS('invalid-target.example.com', Record::TYPE_CNAME, ['8.8.8.8', '1.1.1.1']);

            $result = $validator->isValid('nonexistent-domain-' . \uniqid() . '.com');

            $this->assertFalse($result);
            $this->assertIsInt($validator->count);
            $this->assertIsString($validator->value);
            $this->assertIsArray($validator->records);
            $this->assertIsString($validator->getDescription());
        });
    }

    public function testCoreDNSFailure(): void
    {
        $this->runInCoroutine(function () {
            // CoreDNS is configured to return cname.localhost. for stage.webapp.com
            $validator = new DNS('cname.localhost.', Record::TYPE_CNAME, ['172.16.238.100', '8.8.8.8']);

            $result = $validator->isValid('stage.webapp.com');
            $this->assertFalse($result);

            $result = $validator->isValid('stage-wrong-cname.webapp.com');
            $this->assertFalse($result);
        });
    }
}
