<?php

declare(strict_types=1);

namespace Utopia\Auth\Tests\Algorithms;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Utopia\Auth\Hashes\Bcrypt;

#[RequiresPhpExtension('utopia')]
final class BcryptTest extends TestCase
{
    private Bcrypt $bcrypt;

    protected function setUp(): void
    {
        $this->bcrypt = new Bcrypt();
    }

    public function testHash(): void
    {
        $password = 'test123';
        $hash = $this->bcrypt->hash($password);

        $this->assertNotEmpty($hash);
        $this->assertStringStartsWith('$2y$', $hash);
        $this->assertTrue($this->bcrypt->verify($password, $hash));
        $this->assertFalse($this->bcrypt->verify('wrongpassword', $hash));
    }

    public function testGetName(): void
    {
        $this->assertSame('bcrypt', $this->bcrypt->getName());
    }

    /**
     * `verify()` is `password_verify()`: for every hash `crypt()` makes (blowfish variants,
     * MD5-crypt, SHA-crypt, DES, extended DES), the same hashes altered or cut short, and
     * passwords with NUL bytes, invalid UTF-8 and more than 72 bytes.
     */
    public function testVerifyAgreesWithPasswordVerify(): void
    {
        $passwords = ['', 'a', 'password', str_repeat('x', 72), str_repeat('x', 73) . 'y', "pa\0ss", "\xff\xfe", 'ünïcødé'];
        $salts = [
            '$2y$04$abcdefghijklmnopqrstuu', '$2a$04$abcdefghijklmnopqrstuu', '$2b$04$abcdefghijklmnopqrstuu',
            '$2x$04$abcdefghijklmnopqrstuu', '$2y$03$abcdefghijklmnopqrstuu', '$2y$04$short', '$1$saltsalt$',
            '$5$rounds=1000$salt$', '$6$rounds=1000$salt$', 'ab', '_J9..salt', '*0', '*1', '$3$', '',
        ];
        foreach ($passwords as $password) {
            foreach ($salts as $salt) {
                $hash = @crypt($password, $salt);
                foreach ([$hash, substr($hash, 0, -1) . 'A', $hash . 'x', substr($hash, 0, 12)] as $candidate) {
                    foreach ($passwords as $attempt) {
                        $this->assertSame(
                            password_verify($attempt, $candidate),
                            $this->bcrypt->verify($attempt, $candidate),
                            json_encode([bin2hex($attempt), $candidate]) ?: '',
                        );
                    }
                }
            }
        }
    }
}
