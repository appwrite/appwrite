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

    public static function validEmailProvider(): array
    {
        return [
            'exact email' => [['user@example.com'], 'user@example.com'],
            'exact email is case insensitive' => [['USER@EXAMPLE.COM'], 'user@example.com'],
            'wildcard domain' => [['*@appwrite.io'], 'user@appwrite.io'],
            'wildcard domain is case insensitive' => [['*@APPWRITE.IO'], 'USER@appwrite.io'],
            'entries are trimmed' => [[' owner@example.com ', ' *@appwrite.io '], 'user@appwrite.io'],
            'mixed exact and wildcard entries' => [['owner@example.com', '*@appwrite.io'], 'owner@example.com'],
        ];
    }

    #[DataProvider('invalidEmailProvider')]
    public function testInvalidEmails(array $whitelist, mixed $email): void
    {
        $validator = new EmailWhitelist($whitelist);

        $this->assertFalse($validator->isValid($email));
    }

    public static function invalidEmailProvider(): array
    {
        return [
            'different exact email' => [['owner@example.com'], 'user@example.com'],
            'different domain' => [['*@appwrite.io'], 'user@example.com'],
            'subdomain' => [['*@appwrite.io'], 'user@team.appwrite.io'],
            'domain suffix' => [['*@appwrite.io'], 'user@evilappwrite.io'],
            'allow all wildcard' => [['*'], 'user@appwrite.io'],
            'local part wildcard' => [['dev-*@appwrite.io'], 'dev-user@appwrite.io'],
            'local part wildcard as literal email' => [['dev-*@appwrite.io'], 'dev-*@appwrite.io'],
            'domain wildcard' => [['*@*.appwrite.io'], 'user@team.appwrite.io'],
            'empty domain wildcard' => [['*@'], 'user@appwrite.io'],
            'missing domain separator' => [['*@appwrite.io'], 'user'],
            'multiple domain separators' => [['*@appwrite.io'], 'user@team@appwrite.io'],
            'non-string value' => [['*@appwrite.io'], null],
        ];
    }
}
