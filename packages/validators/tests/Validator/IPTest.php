<?php

declare(strict_types=1);

/**
 * Utopia Http
 * @package Http
 * @subpackage Tests
 *
 * @link https://github.com/utopia-php/Http
 * @author Appwrite Team <team@appwrite.io>
 * @version 1.0 RC4
 * @license The MIT License (MIT) <http://www.opensource.org/licenses/mit-license.php>
 */

namespace Utopia\Validator;

use PHPUnit\Framework\TestCase;

final class IPTest extends TestCase
{
    protected IP $validator;

    public function testIsValidIP(): void
    {
        $validator = new IP();

        // Assertions
        $this->assertTrue($validator->isValid('2001:0db8:85a3:08d3:1319:8a2e:0370:7334'));
        $this->assertTrue($validator->isValid('109.67.204.101'));
        $this->assertFalse($validator->isValid(23.5));
        $this->assertFalse($validator->isValid('23.5'));
        $this->assertFalse($validator->isValid(null));
        $this->assertFalse($validator->isValid(true));
        $this->assertFalse($validator->isValid(false));
        $this->assertSame('string', $validator->getType());
    }

    public function testIsValidIPALL(): void
    {
        $validator = new IP(IP::ALL);

        // Assertions
        $this->assertTrue($validator->isValid('2001:0db8:85a3:08d3:1319:8a2e:0370:7334'));
        $this->assertTrue($validator->isValid('109.67.204.101'));
        $this->assertFalse($validator->isValid(23.5));
        $this->assertFalse($validator->isValid('23.5'));
        $this->assertFalse($validator->isValid(null));
        $this->assertFalse($validator->isValid(true));
        $this->assertFalse($validator->isValid(false));
    }

    public function testIsValidIPV4(): void
    {
        $validator = new IP(IP::V4);

        // Assertions
        $this->assertFalse($validator->isValid('2001:0db8:85a3:08d3:1319:8a2e:0370:7334'));
        $this->assertTrue($validator->isValid('109.67.204.101'));
        $this->assertFalse($validator->isValid(23.5));
        $this->assertFalse($validator->isValid('23.5'));
        $this->assertFalse($validator->isValid(null));
        $this->assertFalse($validator->isValid(true));
        $this->assertFalse($validator->isValid(false));
    }

    public function testIsValidIPV6(): void
    {
        $validator = new IP(IP::V6);

        // Assertions
        $this->assertTrue($validator->isValid('2001:0db8:85a3:08d3:1319:8a2e:0370:7334'));
        $this->assertFalse($validator->isValid('109.67.204.101'));
        $this->assertFalse($validator->isValid(23.5));
        $this->assertFalse($validator->isValid('23.5'));
        $this->assertFalse($validator->isValid(null));
        $this->assertFalse($validator->isValid(true));
        $this->assertFalse($validator->isValid(false));
    }
}
