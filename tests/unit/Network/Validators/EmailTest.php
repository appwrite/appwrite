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

class EmailTest extends TestCase
{
    /**
     * @var Email
     */
    protected $email = null;

    public function setUp():void
    {
        $this->email = new Email();
    }

    public function tearDown():void
    {
        $this->email = null;
    }

    public function testIsValid()
    {
        // Assertions
        $this->assertEquals(true, $this->email->isValid('email@domain.com'));
        $this->assertEquals(true, $this->email->isValid('firstname.lastname@domain.com'));
        $this->assertEquals(true, $this->email->isValid('email@subdomain.domain.com'));
        $this->assertEquals(true, $this->email->isValid('firstname+lastname@domain.com'));
        $this->assertEquals(true, $this->email->isValid('email@[123.123.123.123]'));
        $this->assertEquals(true, $this->email->isValid('"email"@domain.com'));
        $this->assertEquals(true, $this->email->isValid('1234567890@domain.com'));
        $this->assertEquals(true, $this->email->isValid('email@domain-one.com'));
        $this->assertEquals(true, $this->email->isValid('_______@domain.com'));
        $this->assertEquals(true, $this->email->isValid('email@domain.name'));
        $this->assertEquals(true, $this->email->isValid('email@domain.co.jp'));
        $this->assertEquals(true, $this->email->isValid('firstname-lastname@domain.com'));
        $this->assertEquals(false, $this->email->isValid(false));
        $this->assertEquals(false, $this->email->isValid(['string', 'string']));
        $this->assertEquals(false, $this->email->isValid(1));
        $this->assertEquals(false, $this->email->isValid(1.2));
        $this->assertEquals(false, $this->email->isValid('plainaddress')); // Missing @ sign and domain
        $this->assertEquals(false, $this->email->isValid('@domain.com')); // Missing username
        $this->assertEquals(false, $this->email->isValid('#@%^%#$@#$@#.com')); // Garbage
        $this->assertEquals(false, $this->email->isValid('Joe Smith <email@domain.com>')); // Encoded html within email is invalid
        $this->assertEquals(false, $this->email->isValid('email.domain.com')); // Missing @
        $this->assertEquals(false, $this->email->isValid('email@domain@domain.com')); // Two @ sign
        $this->assertEquals(false, $this->email->isValid('.email@domain.com')); // Leading dot in address is not allowed
        $this->assertEquals(false, $this->email->isValid('email.@domain.com')); // Trailing dot in address is not allowed
        $this->assertEquals(false, $this->email->isValid('email..email@domain.com')); // Multiple dots
        $this->assertEquals(false, $this->email->isValid('あいうえお@domain.com')); // Unicode char as address
        $this->assertEquals(false, $this->email->isValid('email@domain.com (Joe Smith)')); // Text followed email is not allowed
        $this->assertEquals(false, $this->email->isValid('email@domain')); // Missing top level domain (.com/.net/.org/etc)
        $this->assertEquals(false, $this->email->isValid('email@-domain.com')); // Leading dash in front of domain is invalid
        $this->assertEquals(false, $this->email->isValid('email@111.222.333.44444')); // Invalid IP format
        $this->assertEquals(false, $this->email->isValid('email@domain..com')); // Multiple dot in the domain portion is invalid
        $this->assertEquals($this->email->getType(), 'string');
    }
}
