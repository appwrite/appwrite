<?php

namespace Tests\Unit\Network\Validators;

use Appwrite\Network\Client;
use Appwrite\Network\Validator\Origin;
use PHPUnit\Framework\TestCase;

class OriginTest extends TestCase
{
    private function buildClients($hostnames = []): array
    {
        $clients = [];

        foreach ($hostnames as $hostname) {
            $clients[] = [
                'type' => Client::TYPE_WEB,
                'hostname' => $hostname,
            ];
        }

        return $clients;
    }

    public function testHostnameValidation(): void
    {
        $validator = new Origin(
            $this->buildClients(['appwrite.io', 'localhost', 'example.com']), // allowed hostnames
        );

        // Valid hostnames
        $this->assertEquals(true, $validator->isValid('appwrite.io'));
        $this->assertEquals(true, $validator->isValid('localhost'));
        $this->assertEquals(true, $validator->isValid('example.com'));

        // Invalid hostnames
        $this->assertEquals(false, $validator->isValid('unauthorized.com'));
        $this->assertEquals(false, $validator->isValid('subdomain.appwrite.io')); // subdomain not in allowed list
        $this->assertEquals(false, $validator->isValid('app-write.io')); // hyphenated variant not in allowed list
        $this->assertEquals(false, $validator->isValid('ftp://appwrite.io')); // valid hostname but bad scheme
    }
}
