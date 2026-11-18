<?php

namespace Utopia\Migration\Tests\Resources;

use PHPUnit\Framework\TestCase;
use Utopia\Migration\Resources\Auth\Policies;
use Utopia\Migration\Resources\Messaging\Topic;
use Utopia\Migration\Resources\Settings\Environment;

final class RoundTripTest extends TestCase
{
    public function testTopicCarriesQosAndExpiry(): void
    {
        $topic = Topic::fromArray((new Topic('news', 'News', ['users'], qos: 1, expiry: 3600))->jsonSerialize());

        $this->assertSame(1, $topic->getQos());
        $this->assertSame(3600, $topic->getExpiry());
    }

    public function testTopicWithoutQosAndExpiryLeavesThemToTheDestination(): void
    {
        $topic = Topic::fromArray(['id' => 'news', 'name' => 'News']);

        $this->assertNull($topic->getQos());
        $this->assertNull($topic->getExpiry());
    }

    public function testPoliciesCarryPwnedRotationPasskeyAndRealtimeGuests(): void
    {
        $policies = Policies::fromArray((new Policies(
            'project',
            passwordPwned: ['enabled' => true, 'sessions' => false, 'users' => true],
            passwordRotation: ['enabled' => true, 'maxAge' => 86400],
            passkeyRpId: 'example.com',
            denyRealtimeGuests: true,
        ))->jsonSerialize());

        $this->assertSame(['enabled' => true, 'sessions' => false, 'users' => true], $policies->getPasswordPwned());
        $this->assertSame(['enabled' => true, 'maxAge' => 86400], $policies->getPasswordRotation());
        $this->assertSame('example.com', $policies->getPasskeyRpId());
        $this->assertTrue($policies->getDenyRealtimeGuests());
    }

    public function testPoliciesFromAnOlderSourceLeaveTheNewOnesUnreported(): void
    {
        $policies = Policies::fromArray(['id' => 'project']);

        $this->assertNull($policies->getPasswordPwned());
        $this->assertNull($policies->getPasswordRotation());
        $this->assertNull($policies->getPasskeyRpId());
        $this->assertNull($policies->getDenyRealtimeGuests());
    }

    public function testEnvironmentCarriesItsName(): void
    {
        $environment = Environment::fromArray((new Environment('staging', 'Staging'))->jsonSerialize());

        $this->assertSame('staging', $environment->getId());
        $this->assertSame('Staging', $environment->getEnvironmentName());
        $this->assertSame('project-environment', Environment::getName());
    }
}
