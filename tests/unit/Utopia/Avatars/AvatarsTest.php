<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Avatars;

use PHPUnit\Framework\TestCase;
use Utopia\Avatars\Adapter\Human\Github;
use Utopia\Avatars\Adapter\Human\Gravatar;
use Utopia\Avatars\Avatars;
use Utopia\Avatars\Exception\InvalidIdentifier;
use Utopia\Avatars\Exception\NotFound;
use Utopia\Avatars\Human;
use Utopia\Fetch\Client;

final class AvatarsTest extends TestCase
{
    public function testGithubAdapterBuildsUrl(): void
    {
        $adapter = new Github(new Client());

        $this->assertSame(
            'https://avatars.githubusercontent.com/appwrite?s=200',
            $adapter->getUrl('appwrite', 200)
        );
    }

    public function testGravatarAdapterBuildsUrl(): void
    {
        $adapter = new Gravatar(new Client());

        $this->assertSame(
            'https://www.gravatar.com/avatar/205e460b479e2e5b48aec07710c55df5?d=404&s=128',
            $adapter->getUrl('205E460B479E2E5B48AEC07710C55DF5', 128)
        );
    }

    public function testGravatarHashesEmail(): void
    {
        $this->assertSame(
            'b642b4217b34b1e8d3bd915fc65c4452',
            Gravatar::hashEmail('test@test.com')
        );
        $this->assertSame(
            'b642b4217b34b1e8d3bd915fc65c4452',
            Gravatar::hashEmail('  TEST@TEST.COM  ')
        );
    }

    public function testGithubAdapterValidatesUsername(): void
    {
        $adapter = new Github(new Client());

        $this->assertTrue($adapter->isValid('appwrite'));
        $this->assertTrue($adapter->isValid('sir-first-walterobrian-junior'));
        $this->assertFalse($adapter->isValid('-invalid'));
        $this->assertFalse($adapter->isValid('invalid-'));
    }

    public function testGravatarAdapterValidatesHash(): void
    {
        $adapter = new Gravatar(new Client());

        $this->assertTrue($adapter->isValid('205e460b479e2e5b48aec07710c55df5'));
        $this->assertFalse($adapter->isValid('not-a-valid-hash'));
        $this->assertFalse($adapter->isValid('205e460b479e2e5b48aec07710c55df'));
    }

    public function testHumanRequiresIdentifier(): void
    {
        $avatars = Avatars::withDefaults();

        $this->expectException(InvalidIdentifier::class);
        $avatars->getHuman(new Human());
    }

    public function testHumanRejectsInvalidGithubUsername(): void
    {
        $avatars = Avatars::withDefaults();

        $this->expectException(InvalidIdentifier::class);
        $avatars->getHuman(new Human(github: '-invalid'));
    }

    public function testHumanRejectsInvalidEmailHash(): void
    {
        $avatars = Avatars::withDefaults();

        $this->expectException(InvalidIdentifier::class);
        $avatars->getHuman(new Human(emailHash: 'not-a-valid-hash'));
    }

    public function testHumanEmailHashTakesPrecedenceOverEmail(): void
    {
        $human = new Human(
            email: 'test@test.com',
            emailHash: '00000000000000000000000000000000',
        );

        $this->assertSame('00000000000000000000000000000000', $human->getGravatarHash());
    }

    public function testHumanResolvesEmailToHash(): void
    {
        $human = new Human(email: 'test@test.com');

        $this->assertSame('b642b4217b34b1e8d3bd915fc65c4452', $human->getGravatarHash());
    }

    public function testDefaultHumanAdaptersAreOrdered(): void
    {
        $avatars = Avatars::withDefaults();
        $reflection = new \ReflectionClass($avatars);
        $property = $reflection->getProperty('humanAdapters');
        $property->setAccessible(true);
        /** @var array<int, Github|Gravatar> $adapters */
        $adapters = $property->getValue($avatars);

        $this->assertSame('github', $adapters[0]->getName());
        $this->assertSame('gravatar', $adapters[1]->getName());
    }

    public function testGetHumanThrowsNotFoundWhenNoImageAvailable(): void
    {
        $avatars = Avatars::withDefaults();

        $this->expectException(NotFound::class);
        $avatars->getHuman(new Human(emailHash: '00000000000000000000000000000000'));
    }

    public function testGetCompanyRequiresIdentifier(): void
    {
        $avatars = Avatars::withDefaults();

        $this->expectException(InvalidIdentifier::class);
        $avatars->getCompany(new \Utopia\Avatars\Company());
    }

    public function testGetCompanyThrowsNotFoundWithoutAdapters(): void
    {
        $avatars = Avatars::withDefaults();

        $this->expectException(NotFound::class);
        $avatars->getCompany(new \Utopia\Avatars\Company(domain: 'appwrite.io'));
    }
}
