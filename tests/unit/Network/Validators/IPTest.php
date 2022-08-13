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

use Appwrite\Network\Validator\IP;
use PHPUnit\Framework\TestCase;

class IPTest extends TestCase
{
    protected ?IP $validator;

    public function setUp(): void
    {
        $this->validator = new IP();
    }

    public function tearDown(): void
    {
        $this->validator = null;
    }

    public function testIsValidIP(): void
    {
        $this->assertEquals($this->validator->isValid('2001:0db8:85a3:08d3:1319:8a2e:0370:7334'), true);
        $this->assertEquals($this->validator->isValid('109.67.204.101'), true);
        $this->assertEquals($this->validator->isValid(23.5), false);
        $this->assertEquals($this->validator->isValid('23.5'), false);
        $this->assertEquals($this->validator->isValid(null), false);
        $this->assertEquals($this->validator->isValid(true), false);
        $this->assertEquals($this->validator->isValid(false), false);
        $this->assertEquals($this->validator->getType(), 'string');
    }

    public function testIsValidIPALL(): void
    {
        $this->validator = new IP(IP::ALL);

        // Assertions
        $this->assertEquals($this->validator->isValid('2001:0db8:85a3:08d3:1319:8a2e:0370:7334'), true);
        $this->assertEquals($this->validator->isValid('109.67.204.101'), true);
        $this->assertEquals($this->validator->isValid(23.5), false);
        $this->assertEquals($this->validator->isValid('23.5'), false);
        $this->assertEquals($this->validator->isValid(null), false);
        $this->assertEquals($this->validator->isValid(true), false);
        $this->assertEquals($this->validator->isValid(false), false);
    }

    public function testIsValidIPV4(): void
    {
        $this->validator = new IP(IP::V4);

        // Assertions
        $this->assertEquals($this->validator->isValid('2001:0db8:85a3:08d3:1319:8a2e:0370:7334'), false);
        $this->assertEquals($this->validator->isValid('109.67.204.101'), true);
        $this->assertEquals($this->validator->isValid(23.5), false);
        $this->assertEquals($this->validator->isValid('23.5'), false);
        $this->assertEquals($this->validator->isValid(null), false);
        $this->assertEquals($this->validator->isValid(true), false);
        $this->assertEquals($this->validator->isValid(false), false);
    }

    public function testIsValidIPV6(): void
    {
        $this->validator = new IP(IP::V6);

        // Assertions
        $this->assertEquals($this->validator->isValid('2001:0db8:85a3:08d3:1319:8a2e:0370:7334'), true);
        $this->assertEquals($this->validator->isValid('109.67.204.101'), false);
        $this->assertEquals($this->validator->isValid(23.5), false);
        $this->assertEquals($this->validator->isValid('23.5'), false);
        $this->assertEquals($this->validator->isValid(null), false);
        $this->assertEquals($this->validator->isValid(true), false);
        $this->assertEquals($this->validator->isValid(false), false);
    }
}
