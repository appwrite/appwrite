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

class URLTest extends TestCase
{
    /**
     * @var Domain
     */
    protected $url = null;

    public function setUp():void
    {
        $this->url = new URL();
    }

    public function tearDown():void
    {
        $this->url = null;
    }

    public function testIsValid()
    {
        // Assertions
        $this->assertEquals(true, $this->url->isValid('http://example.com'));
        $this->assertEquals(true, $this->url->isValid('https://example.com'));
        $this->assertEquals(true, $this->url->isValid('htts://example.com')); // does not validate protocol
        $this->assertEquals(false, $this->url->isValid('example.com')); // though, requires some kind of protocol
        $this->assertEquals(false, $this->url->isValid('http:/example.com')); 
        $this->assertEquals(true, $this->url->isValid('http://exa-mple.com'));
        $this->assertEquals(false, $this->url->isValid('htt@s://example.com'));
        $this->assertEquals(true, $this->url->isValid('http://www.example.com/foo%2\u00c2\u00a9zbar'));
        $this->assertEquals(true, $this->url->isValid('http://www.example.com/?q=%3Casdf%3E'));
    }
}
