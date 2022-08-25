<?php

namespace Appwrite\Tests;

use Appwrite\Network\Validator\Origin;
use PHPUnit\Framework\TestCase;

class OriginTest extends TestCase
{
    public function testValues()
    {
        $validator = new Origin([
            [
                '$collection' => 'platforms',
                'name' => 'Production',
                'type' => 'web',
                'hostname' => 'appwrite.io',
            ],
            [
                '$collection' => 'platforms',
                'name' => 'Development',
                'type' => 'web',
                'hostname' => 'appwrite.test',
            ],
            [
                '$collection' => 'platforms',
                'name' => 'Localhost',
                'type' => 'web',
                'hostname' => 'localhost',
            ],
        ]);

        $this->assertEquals(true, $validator->isValid('https://localhost'));
        $this->assertEquals(true, $validator->isValid('http://localhost'));
        $this->assertEquals(true, $validator->isValid('http://localhost:80'));

        $this->assertEquals(true, $validator->isValid('https://appwrite.io'));
        $this->assertEquals(true, $validator->isValid('http://appwrite.io'));
        $this->assertEquals(true, $validator->isValid('http://appwrite.io:80'));

        $this->assertEquals(true, $validator->isValid('https://appwrite.test'));
        $this->assertEquals(true, $validator->isValid('http://appwrite.test'));
        $this->assertEquals(true, $validator->isValid('http://appwrite.test:80'));

        $this->assertEquals(false, $validator->isValid('https://example.com'));
        $this->assertEquals(false, $validator->isValid('http://example.com'));
        $this->assertEquals(false, $validator->isValid('http://example.com:80'));

        $this->assertEquals(false, $validator->isValid('appwrite-ios://com.company.appname'));
        $this->assertEquals('Invalid Origin. Register your new client (com.company.appname) as a new iOS platform on your project console dashboard', $validator->getDescription());

        $this->assertEquals(false, $validator->isValid('appwrite-android://com.company.appname'));
        $this->assertEquals('Invalid Origin. Register your new client (com.company.appname) as a new Android platform on your project console dashboard', $validator->getDescription());

        $this->assertEquals(false, $validator->isValid('appwrite-macos://com.company.appname'));
        $this->assertEquals('Invalid Origin. Register your new client (com.company.appname) as a new macOS platform on your project console dashboard', $validator->getDescription());

        $this->assertEquals(false, $validator->isValid('appwrite-linux://com.company.appname'));
        $this->assertEquals('Invalid Origin. Register your new client (com.company.appname) as a new Linux platform on your project console dashboard', $validator->getDescription());

        $this->assertEquals(false, $validator->isValid('appwrite-windows://com.company.appname'));
        $this->assertEquals('Invalid Origin. Register your new client (com.company.appname) as a new Windows platform on your project console dashboard', $validator->getDescription());
    }
}
