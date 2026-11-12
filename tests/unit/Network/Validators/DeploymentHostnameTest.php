<?php

declare(strict_types=1);

namespace Tests\Unit\Network\Validators;

use Appwrite\Network\Validator\DeploymentHostname;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeploymentHostnameTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function migrationHosts(): iterable
    {
        yield 'bare host' => ['appwrite'];
        yield 'host and port' => ['appwrite:8080'];
        yield 'http URL' => ['http://appwrite'];
        yield 'https URL with port and path' => ['https://appwrite:8443/v1'];
        yield 'surrounding whitespace' => [' appwrite '];
        yield 'uppercase' => ['APPWRITE'];
    }

    #[DataProvider('migrationHosts')]
    public function testMigrationHostIsAcceptedInEveryForm(string $migrationHost): void
    {
        $validator = new DeploymentHostname('', $migrationHost);

        $this->assertTrue($validator->isValid('appwrite'));
        $this->assertFalse($validator->isValid('http'));
        $this->assertFalse($validator->isValid('https'));
        $this->assertFalse($validator->isValid('example.com'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function domains(): iterable
    {
        yield 'bare host' => ['cloud.example.com'];
        yield 'host and port' => ['cloud.example.com:443'];
        yield 'https URL' => ['https://cloud.example.com'];
    }

    #[DataProvider('domains')]
    public function testDomainAndItsSubdomainsAreAccepted(string $domain): void
    {
        $validator = new DeploymentHostname($domain, '');

        $this->assertTrue($validator->isValid('cloud.example.com'));
        $this->assertTrue($validator->isValid('fra.cloud.example.com'));
        $this->assertTrue($validator->isValid('FRA.Cloud.Example.com'));
        $this->assertFalse($validator->isValid('example.com'));
        $this->assertFalse($validator->isValid('cloud.example.com.evil.com'));
        $this->assertFalse($validator->isValid('evilcloud.example.com'));
    }

    public function testNothingIsAcceptedWhenNothingIsConfigured(): void
    {
        $validator = new DeploymentHostname('', '');

        $this->assertFalse($validator->isValid('appwrite'));
        $this->assertFalse($validator->isValid('example.com'));
    }

    public function testRejectsWhatIsNotAHostname(): void
    {
        $validator = new DeploymentHostname('cloud.example.com', 'appwrite');

        foreach (['', null, false, 123, [], 'appwrite:80', 'http://appwrite'] as $value) {
            $this->assertFalse($validator->isValid($value), \var_export($value, true));
        }
    }
}
