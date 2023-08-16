<?php

namespace Tests\Unit\Network\Validators;

use Appwrite\Network\Validator\Origin;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Helpers\ID;

class OriginTest extends TestCase
{
    public function testValues(): void
    {
        $validator = new Origin([
            [
                '$collection' => ID::custom('platforms'),
                'name' => 'Production',
                'type' => 'web',
                'hostname' => 'appwrite.io',
            ],
            [
                '$collection' => ID::custom('platforms'),
                'name' => 'Development',
                'type' => 'web',
                'hostname' => 'appwrite.test',
            ],
            [
                '$collection' => ID::custom('platforms'),
                'name' => 'Localhost',
                'type' => 'web',
                'hostname' => 'localhost',
            ],
        ]);

        $this->assertEquals($validator->isValid('https://localhost'), true);
        $this->assertEquals($validator->isValid('http://localhost'), true);
        $this->assertEquals($validator->isValid('http://localhost:80'), true);

        $this->assertEquals($validator->isValid('https://appwrite.io'), true);
        $this->assertEquals($validator->isValid('http://appwrite.io'), true);
        $this->assertEquals($validator->isValid('http://appwrite.io:80'), true);

        $this->assertEquals($validator->isValid('https://appwrite.test'), true);
        $this->assertEquals($validator->isValid('http://appwrite.test'), true);
        $this->assertEquals($validator->isValid('http://appwrite.test:80'), true);

        $this->assertEquals($validator->isValid('https://example.com'), false);
        $this->assertEquals($validator->isValid('http://example.com'), false);
        $this->assertEquals($validator->isValid('http://example.com:80'), false);

        $this->assertEquals($validator->isValid('appwrite-ios://com.company.appname'), false);
        $this->assertEquals($validator->getDescription(), 'Invalid Origin. Register your new client (com.company.appname) as a new iOS platform on your project console dashboard');

        $this->assertEquals($validator->isValid('appwrite-android://com.company.appname'), false);
        $this->assertEquals($validator->getDescription(), 'Invalid Origin. Register your new client (com.company.appname) as a new Android platform on your project console dashboard');

        $this->assertEquals($validator->isValid('appwrite-macos://com.company.appname'), false);
        $this->assertEquals($validator->getDescription(), 'Invalid Origin. Register your new client (com.company.appname) as a new macOS platform on your project console dashboard');

        $this->assertEquals($validator->isValid('appwrite-linux://com.company.appname'), false);
        $this->assertEquals($validator->getDescription(), 'Invalid Origin. Register your new client (com.company.appname) as a new Linux platform on your project console dashboard');

        $this->assertEquals($validator->isValid('appwrite-windows://com.company.appname'), false);
        $this->assertEquals($validator->getDescription(), 'Invalid Origin. Register your new client (com.company.appname) as a new Windows platform on your project console dashboard');
    }
}
