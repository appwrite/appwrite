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

namespace Utopia\Validator;

use PHPUnit\Framework\TestCase;

class IPTest extends TestCase
{
    public function tearDown():void
    {
        $this->validator = null;
    }

    public function testIsValidIP()
    {
        $validator = new IP();

        // Assertions
        $this->assertEquals($validator->isValid('2001:0db8:85a3:08d3:1319:8a2e:0370:7334'), true);
        $this->assertEquals($validator->isValid('109.67.204.101'), true);
        $this->assertEquals($validator->isValid(23.5), false);
        $this->assertEquals($validator->isValid('23.5'), false);
        $this->assertEquals($validator->isValid(null), false);
        $this->assertEquals($validator->isValid(true), false);
        $this->assertEquals($validator->isValid(false), false);
        $this->assertEquals($validator->getType(), 'string');
    }

    public function testIsValidIPALL()
    {
        $validator = new IP(IP::ALL);

        // Assertions
        $this->assertEquals($validator->isValid('2001:0db8:85a3:08d3:1319:8a2e:0370:7334'), true);
        $this->assertEquals($validator->isValid('109.67.204.101'), true);
        $this->assertEquals($validator->isValid(23.5), false);
        $this->assertEquals($validator->isValid('23.5'), false);
        $this->assertEquals($validator->isValid(null), false);
        $this->assertEquals($validator->isValid(true), false);
        $this->assertEquals($validator->isValid(false), false);
    }

    public function testIsValidIPV4()
    {
        $validator = new IP(IP::V4);

        // Assertions
        $this->assertEquals($validator->isValid('2001:0db8:85a3:08d3:1319:8a2e:0370:7334'), false);
        $this->assertEquals($validator->isValid('109.67.204.101'), true);
        $this->assertEquals($validator->isValid(23.5), false);
        $this->assertEquals($validator->isValid('23.5'), false);
        $this->assertEquals($validator->isValid(null), false);
        $this->assertEquals($validator->isValid(true), false);
        $this->assertEquals($validator->isValid(false), false);
    }

    public function testIsValidIPV6()
    {
        $validator = new IP(IP::V6);

        // Assertions
        $this->assertEquals($validator->isValid('2001:0db8:85a3:08d3:1319:8a2e:0370:7334'), true);
        $this->assertEquals($validator->isValid('109.67.204.101'), false);
        $this->assertEquals($validator->isValid(23.5), false);
        $this->assertEquals($validator->isValid('23.5'), false);
        $this->assertEquals($validator->isValid(null), false);
        $this->assertEquals($validator->isValid(true), false);
        $this->assertEquals($validator->isValid(false), false);
    }
}
