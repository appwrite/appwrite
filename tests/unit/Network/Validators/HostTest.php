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

namespace Appwrite\Network\Validator;

use PHPUnit\Framework\TestCase;

class HostTest extends TestCase
{
    /**
     * @var Host
     */
    protected $host = null;

    public function setUp(): void
    {
        $this->host = new Host(['appwrite.io', 'subdomain.appwrite.test', 'localhost']);
    }

    public function tearDown(): void
    {
        $this->host = null;
    }

    public function testIsValid()
    {
        // Assertions
        $this->assertEquals(true, $this->host->isValid('https://appwrite.io/link'));
        $this->assertEquals(true, $this->host->isValid('https://localhost'));
        $this->assertEquals(false, $this->host->isValid('localhost'));
        $this->assertEquals(true, $this->host->isValid('http://subdomain.appwrite.test/path'));
        $this->assertEquals(false, $this->host->isValid('http://test.subdomain.appwrite.test/path'));
        $this->assertEquals('string', $this->host->getType());
    }
}
