<?php

namespace Tests\Unit\Network\Validators;

use Appwrite\Network\Validator\Origin;
use PHPUnit\Framework\TestCase;

class OriginTest extends TestCase
{
    public function testValues(): void
    {
        $validator = new Origin(
            allowedHostnames: ['appwrite.io', 'appwrite.test', 'localhost', 'appwrite.flutter'],
            allowedSchemes: ['exp', 'appwrite-callback-123']
        );

        $this->assertEquals(false, $validator->isValid(''));
        $this->assertEquals(false, $validator->isValid('/'));

        $this->assertEquals(true, $validator->isValid('https://localhost'));
        $this->assertEquals(true, $validator->isValid('http://localhost'));
        $this->assertEquals(true, $validator->isValid('http://localhost:80'));

        $this->assertEquals(true, $validator->isValid('https://appwrite.io'));
        $this->assertEquals(true, $validator->isValid('http://appwrite.io'));
        $this->assertEquals(true, $validator->isValid('http://appwrite.io:80'));

        $this->assertEquals(true, $validator->isValid('https://appwrite.test'));
        $this->assertEquals(true, $validator->isValid('http://appwrite.test'));
        $this->assertEquals(true, $validator->isValid('http://appwrite.test:80'));

        $this->assertEquals(true, $validator->isValid('https://appwrite.flutter'));
        $this->assertEquals(true, $validator->isValid('http://appwrite.flutter'));
        $this->assertEquals(true, $validator->isValid('http://appwrite.flutter:80'));

        $this->assertEquals(false, $validator->isValid('https://example.com'));
        $this->assertEquals(false, $validator->isValid('http://example.com'));
        $this->assertEquals(false, $validator->isValid('http://example.com:80'));

        $this->assertEquals(true, $validator->isValid('exp://'));
        $this->assertEquals(true, $validator->isValid('exp:///'));
        $this->assertEquals(true, $validator->isValid('exp://index'));

        $this->assertEquals(true, $validator->isValid('appwrite-callback-123://'));
        $this->assertEquals(false, $validator->isValid('appwrite-callback-456://'));

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

        $this->assertEquals(false, $validator->isValid('chrome-extension://com.company.appname'));
        $this->assertEquals('Invalid Origin. Register your new client (com.company.appname) as a new Web (Chrome Extension) platform on your project console dashboard', $validator->getDescription());

        $this->assertEquals(false, $validator->isValid('moz-extension://com.company.appname'));
        $this->assertEquals('Invalid Origin. Register your new client (com.company.appname) as a new Web (Firefox Extension) platform on your project console dashboard', $validator->getDescription());

        $this->assertEquals(false, $validator->isValid('safari-web-extension://com.company.appname'));
        $this->assertEquals('Invalid Origin. Register your new client (com.company.appname) as a new Web (Safari Extension) platform on your project console dashboard', $validator->getDescription());

        $this->assertEquals(false, $validator->isValid('ms-browser-extension://com.company.appname'));
        $this->assertEquals('Invalid Origin. Register your new client (com.company.appname) as a new Web (Edge Extension) platform on your project console dashboard', $validator->getDescription());

        $this->assertEquals(false, $validator->isValid('random-scheme://localhost'));
        $this->assertEquals('Invalid Scheme. The scheme used (random-scheme) in the Origin (random-scheme://localhost) is not supported. If you are using a custom scheme, please change it to `appwrite-callback-<PROJECT_ID>`', $validator->getDescription());
    }
}
