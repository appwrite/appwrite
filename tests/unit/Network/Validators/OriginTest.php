<?php

declare(strict_types=1);

namespace Tests\Unit\Network\Validators;

use Appwrite\Network\Validator\Origin;
use PHPUnit\Framework\TestCase;

final class OriginTest extends TestCase
{
    public function testValues(): void
    {
        $validator = new Origin(
            allowedHostnames: ['appwrite.io', 'appwrite.test', 'localhost', 'appwrite.flutter'],
            allowedSchemes: ['exp', 'appwrite-callback-123']
        );

        $this->assertEquals(false, $validator->isValid(''));
        $this->assertEquals(false, $validator->isValid('/'));
        $this->assertEquals(false, $validator->isValid([]));
        $this->assertEquals(false, $validator->isValid(['http://localhost']));

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
        $this->assertSame('Invalid Origin. Register your new client (com.company.appname) as a new iOS platform on your project console dashboard', $validator->getDescription());

        $this->assertEquals(false, $validator->isValid('appwrite-android://com.company.appname'));
        $this->assertSame('Invalid Origin. Register your new client (com.company.appname) as a new Android platform on your project console dashboard', $validator->getDescription());

        $this->assertEquals(false, $validator->isValid('appwrite-macos://com.company.appname'));
        $this->assertSame('Invalid Origin. Register your new client (com.company.appname) as a new macOS platform on your project console dashboard', $validator->getDescription());

        $this->assertEquals(false, $validator->isValid('appwrite-linux://com.company.appname'));
        $this->assertSame('Invalid Origin. Register your new client (com.company.appname) as a new Linux platform on your project console dashboard', $validator->getDescription());

        $this->assertEquals(false, $validator->isValid('appwrite-windows://com.company.appname'));
        $this->assertSame('Invalid Origin. Register your new client (com.company.appname) as a new Windows platform on your project console dashboard', $validator->getDescription());

        $this->assertEquals(false, $validator->isValid('chrome-extension://com.company.appname'));
        $this->assertSame('Invalid Origin. Register your new client (com.company.appname) as a new Web (Chrome Extension) platform on your project console dashboard', $validator->getDescription());

        $this->assertEquals(false, $validator->isValid('moz-extension://com.company.appname'));
        $this->assertSame('Invalid Origin. Register your new client (com.company.appname) as a new Web (Firefox Extension) platform on your project console dashboard', $validator->getDescription());

        $this->assertEquals(false, $validator->isValid('safari-web-extension://com.company.appname'));
        $this->assertSame('Invalid Origin. Register your new client (com.company.appname) as a new Web (Safari Extension) platform on your project console dashboard', $validator->getDescription());

        $this->assertEquals(false, $validator->isValid('ms-browser-extension://com.company.appname'));
        $this->assertSame('Invalid Origin. Register your new client (com.company.appname) as a new Web (Edge Extension) platform on your project console dashboard', $validator->getDescription());

        $this->assertEquals(true, $validator->isValid('tauri://localhost'));
        $this->assertEquals(false, $validator->isValid('tauri://example.com'));
        $this->assertSame('Invalid Origin. Register your new client (example.com) as a new Web (Tauri) platform on your project console dashboard', $validator->getDescription());

        $this->assertEquals(false, $validator->isValid('random-scheme://localhost'));
        $this->assertSame('Invalid Scheme. The scheme used (random-scheme) in the Origin (random-scheme://localhost) is not supported. If you are using a custom scheme, please change it to `appwrite-callback-<PROJECT_ID>`', $validator->getDescription());
    }

    public function testLoopback(): void
    {
        $validator = new Origin(
            allowedHostnames: ['appwrite.io', 'localhost'],
            allowedSchemes: ['exp']
        );

        /* Allowing localhost allows its other spellings too */
        $this->assertEquals(true, $validator->isValid('http://localhost'));
        $this->assertEquals(true, $validator->isValid('http://localhost:3000'));
        $this->assertEquals(true, $validator->isValid('http://127.0.0.1'));
        $this->assertEquals(true, $validator->isValid('https://127.0.0.1:5173'));
        $this->assertEquals(true, $validator->isValid('http://[::1]'));
        $this->assertEquals(true, $validator->isValid('http://[::1]:3000'));

        /* Hostnames that only look like loopback are still rejected */
        $this->assertEquals(false, $validator->isValid('http://127.0.0.1.example.com'));
        $this->assertEquals(false, $validator->isValid('http://localhost.example.com'));
        $this->assertEquals(false, $validator->isValid('http://127.0.0.1@example.com'));
        $this->assertEquals(false, $validator->isValid('http://128.0.0.1'));
        $this->assertEquals(false, $validator->isValid('http://[2001:db8::1]'));

        /* A prefix or substring match would wrongly accept these */
        $this->assertEquals(false, $validator->isValid('http://xlocalhost'));
        $this->assertEquals(false, $validator->isValid('http://127.0.0.1x.example.com'));
        $this->assertEquals(false, $validator->isValid('http://[::1].evil.com'));
        $this->assertEquals(false, $validator->isValid('http://[::1]evil.com'));

        /* Only the exact loopback spellings are hardcoded */
        $this->assertEquals(false, $validator->isValid('http://127.0.0.2'));
        $this->assertEquals(false, $validator->isValid('http://[0:0:0:0:0:0:0:1]'));
        $this->assertEquals(false, $validator->isValid('http://localhost.'));

        /* Loopback does not bypass the scheme allow list */
        $this->assertEquals(false, $validator->isValid('random-scheme://127.0.0.1'));
    }

    public function testLoopbackRequiresLocalhostToBeAllowed(): void
    {
        /* A deployment that does not trust localhost trusts no spelling of it */
        $validator = new Origin(
            allowedHostnames: ['appwrite.io'],
            allowedSchemes: ['exp']
        );

        $this->assertEquals(false, $validator->isValid('http://localhost'));
        $this->assertEquals(false, $validator->isValid('http://localhost:3000'));
        $this->assertEquals(false, $validator->isValid('http://127.0.0.1'));
        $this->assertEquals(false, $validator->isValid('https://127.0.0.1:5173'));
        $this->assertEquals(false, $validator->isValid('http://[::1]'));
        $this->assertEquals(false, $validator->isValid('http://[::1]:3000'));
    }

    public function testLoopbackAliasHonoursAnExplicitEntry(): void
    {
        /* An explicitly allowed literal still matches on its own */
        $validator = new Origin(
            allowedHostnames: ['127.0.0.1'],
            allowedSchemes: ['exp']
        );

        $this->assertEquals(true, $validator->isValid('http://127.0.0.1:5173'));
        $this->assertEquals(false, $validator->isValid('http://localhost'));
        $this->assertEquals(false, $validator->isValid('http://[::1]'));
    }

    public function testGetAllowedHostnames(): void
    {
        $validator = new Origin(
            allowedHostnames: ['appwrite.io', 'localhost'],
            allowedSchemes: ['exp']
        );

        $this->assertSame(['appwrite.io', 'localhost'], $validator->getAllowedHostnames());
    }

    public function testGetAllowedSchemes(): void
    {
        $validator = new Origin(
            allowedHostnames: ['appwrite.io'],
            allowedSchemes: ['exp', 'appwrite-callback-123']
        );

        $this->assertSame(['exp', 'appwrite-callback-123'], $validator->getAllowedSchemes());
    }

    public function testSetAllowedHostnames(): void
    {
        $validator = new Origin(
            allowedHostnames: ['appwrite.io'],
            allowedSchemes: ['exp']
        );

        $this->assertEquals(true, $validator->isValid('https://appwrite.io'));
        $this->assertEquals(false, $validator->isValid('https://example.com'));

        $result = $validator->setAllowedHostnames(['example.com']);

        $this->assertSame($validator, $result);
        $this->assertSame(['example.com'], $validator->getAllowedHostnames());
        $this->assertEquals(true, $validator->isValid('https://example.com'));
        $this->assertEquals(false, $validator->isValid('https://appwrite.io'));
    }

    public function testSetAllowedSchemes(): void
    {
        $validator = new Origin(
            allowedHostnames: ['appwrite.io'],
            allowedSchemes: ['exp']
        );

        $this->assertEquals(true, $validator->isValid('exp://'));
        $this->assertEquals(false, $validator->isValid('appwrite-callback-456://'));

        $result = $validator->setAllowedSchemes(['appwrite-callback-456']);

        $this->assertSame($validator, $result);
        $this->assertSame(['appwrite-callback-456'], $validator->getAllowedSchemes());
        $this->assertEquals(true, $validator->isValid('appwrite-callback-456://'));
        $this->assertEquals(false, $validator->isValid('exp://'));
    }
}
