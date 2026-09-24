<?php

declare(strict_types=1);

/**
 * Utopia Http
 *
 * @package Http
 * @subpackage Tests
 *
 * @link https://github.com/utopia-php/http
 * @author Appwrite Team <team@appwrite.io>
 * @version 1.0 RC4
 * @license The MIT License (MIT) <http://www.opensource.org/licenses/mit-license.php>
 */

namespace Utopia\Validator;

use PHPUnit\Framework\TestCase;

final class HostTest extends TestCase
{
    protected Host $host;

    public function setUp(): void
    {
        $this->host = new Host(['example.io', 'subdomain.example.test', 'localhost', '*.appwrite.io']);
    }

    public function testIsValid(): void
    {
        // Assertions
        $this->assertTrue($this->host->isValid('https://example.io/link'));
        $this->assertTrue($this->host->isValid('https://localhost'));
        $this->assertFalse($this->host->isValid('localhost'));
        $this->assertTrue($this->host->isValid('http://subdomain.example.test/path'));
        $this->assertFalse($this->host->isValid('http://test.subdomain.example.test/path'));
        $this->assertFalse($this->host->isValid('http://appwrite.io/path'));
        $this->assertTrue($this->host->isValid('http://me.appwrite.io/path'));
        $this->assertTrue($this->host->isValid('http://you.appwrite.io/path'));
        $this->assertTrue($this->host->isValid('http://us.together.appwrite.io/path'));
        $this->assertSame('string', $this->host->getType());
    }
}
