<?php

/**
 * Utopia PHP Framework
 *
 * @package Framework
 * @subpackage Tests
 *
 * @link https://github.com/utopia-php/framework
 * @author Appwrite Team <team@appwrite.io>
 * @version 1.0 RC4
 * @license The MIT License (MIT) <http://www.opensource.org/licenses/mit-license.php>
 */

namespace Tests\Unit\Network\Validators;

use Utopia\Validator\Host;
use PHPUnit\Framework\TestCase;

class HostTest extends TestCase
{
    protected ?Host $host = null;

    public function setUp(): void
    {
        $this->host = new Host(['appwrite.io', 'subdomain.appwrite.test', 'localhost']);
    }

    public function tearDown(): void
    {
        $this->host = null;
    }

    public function testIsValid(): void
    {
        // Assertions
        $this->assertEquals($this->host->isValid('https://appwrite.io/link'), true);
        $this->assertEquals($this->host->isValid('https://localhost'), true);
        $this->assertEquals($this->host->isValid('localhost'), false);
        $this->assertEquals($this->host->isValid('http://subdomain.appwrite.test/path'), true);
        $this->assertEquals($this->host->isValid('http://test.subdomain.appwrite.test/path'), false);
        $this->assertEquals($this->host->getType(), 'string');
    }
}
