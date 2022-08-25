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

class IPTest extends TestCase
{
    public function tearDown(): void
    {
        $this->validator = null;
    }

    public function testIsValidIP()
    {
        $validator = new IP();

        // Assertions
        $this->assertEquals(true, $validator->isValid('2001:0db8:85a3:08d3:1319:8a2e:0370:7334'));
        $this->assertEquals(true, $validator->isValid('109.67.204.101'));
        $this->assertEquals(false, $validator->isValid(23.5));
        $this->assertEquals(false, $validator->isValid('23.5'));
        $this->assertEquals(false, $validator->isValid(null));
        $this->assertEquals(false, $validator->isValid(true));
        $this->assertEquals(false, $validator->isValid(false));
        $this->assertEquals('string', $validator->getType());
    }

    public function testIsValidIPALL()
    {
        $validator = new IP(IP::ALL);

        // Assertions
        $this->assertEquals(true, $validator->isValid('2001:0db8:85a3:08d3:1319:8a2e:0370:7334'));
        $this->assertEquals(true, $validator->isValid('109.67.204.101'));
        $this->assertEquals(false, $validator->isValid(23.5));
        $this->assertEquals(false, $validator->isValid('23.5'));
        $this->assertEquals(false, $validator->isValid(null));
        $this->assertEquals(false, $validator->isValid(true));
        $this->assertEquals(false, $validator->isValid(false));
    }

    public function testIsValidIPV4()
    {
        $validator = new IP(IP::V4);

        // Assertions
        $this->assertEquals(false, $validator->isValid('2001:0db8:85a3:08d3:1319:8a2e:0370:7334'));
        $this->assertEquals(true, $validator->isValid('109.67.204.101'));
        $this->assertEquals(false, $validator->isValid(23.5));
        $this->assertEquals(false, $validator->isValid('23.5'));
        $this->assertEquals(false, $validator->isValid(null));
        $this->assertEquals(false, $validator->isValid(true));
        $this->assertEquals(false, $validator->isValid(false));
    }

    public function testIsValidIPV6()
    {
        $validator = new IP(IP::V6);

        // Assertions
        $this->assertEquals(true, $validator->isValid('2001:0db8:85a3:08d3:1319:8a2e:0370:7334'));
        $this->assertEquals(false, $validator->isValid('109.67.204.101'));
        $this->assertEquals(false, $validator->isValid(23.5));
        $this->assertEquals(false, $validator->isValid('23.5'));
        $this->assertEquals(false, $validator->isValid(null));
        $this->assertEquals(false, $validator->isValid(true));
        $this->assertEquals(false, $validator->isValid(false));
    }
}
