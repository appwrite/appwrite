<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\Validator;

use Appwrite\Auth\Validator\EmailWhitelist;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EmailWhitelistTest extends TestCase
{
    #[DataProvider('validEmailProvider')]
    public function testValidEmails(array $whitelist, string $email): void
    {
        $validator = new EmailWhitelist($whitelist);

        $this->assertTrue($validator->isValid($email));
    }

    public static function validEmailProvider(): \Iterator
    {
        yield 'exact email' => [['user@example.com'], 'user@example.com'];
        yield 'exact email is case insensitive' => [['USER@EXAMPLE.COM'], 'user@example.com'];
        yield 'wildcard domain' => [['*@appwrite.io'], 'user@appwrite.io'];
        yield 'wildcard domain is case insensitive' => [['*@APPWRITE.IO'], 'USER@appwrite.io'];
        yield 'entries are trimmed' => [[' owner@example.com ', ' *@appwrite.io '], 'user@appwrite.io'];
        yield 'mixed exact and wildcard entries' => [['owner@example.com', '*@appwrite.io'], 'owner@example.com'];
    }

    #[DataProvider('invalidEmailProvider')]
    public function testInvalidEmails(array $whitelist, mixed $email): void
    {
        $validator = new EmailWhitelist($whitelist);

        $this->assertFalse($validator->isValid($email));
    }

    public static function invalidEmailProvider(): \Iterator
    {
        yield 'different exact email' => [['owner@example.com'], 'user@example.com'];
        yield 'different domain' => [['*@appwrite.io'], 'user@example.com'];
        yield 'subdomain' => [['*@appwrite.io'], 'user@team.appwrite.io'];
        yield 'domain suffix' => [['*@appwrite.io'], 'user@evilappwrite.io'];
        yield 'allow all wildcard' => [['*'], 'user@appwrite.io'];
        yield 'local part wildcard' => [['dev-*@appwrite.io'], 'dev-user@appwrite.io'];
        yield 'local part wildcard as literal email' => [['dev-*@appwrite.io'], 'dev-*@appwrite.io'];
        yield 'domain wildcard' => [['*@*.appwrite.io'], 'user@team.appwrite.io'];
        yield 'empty domain wildcard' => [['*@'], 'user@appwrite.io'];
        yield 'missing domain separator' => [['*@appwrite.io'], 'user'];
        yield 'multiple domain separators' => [['*@appwrite.io'], 'user@team@appwrite.io'];
        yield 'non-string value' => [['*@appwrite.io'], null];
    }
}
