<?php

declare(strict_types=1);

namespace Tests\Unit\Messaging;

use Appwrite\Messaging\Provider;
use Appwrite\Network\Validator\PublicHostname;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Network\FixedLookup;
use Utopia\Client\Destinations\PublicInternet;
use Utopia\Config\Config;
use Utopia\Database\Document;
use Utopia\Messaging\Adapter\Email\Bird as BirdEmail;
use Utopia\Messaging\Adapter\SMS\Bird;
use Utopia\Telemetry\Adapter\None as NoneTelemetry;

final class ProviderTest extends TestCase
{
    private Provider $providerFactory;

    protected function setUp(): void
    {
        Config::setParam('errors', include __DIR__ . '/../../../app/config/errors.php');

        $this->providerFactory = new Provider(
            new NoneTelemetry(),
            new PublicHostname(new PublicInternet(), new FixedLookup())
        );
    }

    public function testProviderFactoryInstantiatesBirdAdapter(): void
    {
        $providerDoc = new Document([
            'provider' => 'bird',
            'credentials' => [
                'apiKey' => 'bk_eu1_mock_payload_checksum',
            ],
            'options' => [
                'from' => '+16175551212',
            ],
        ]);

        $adapter = $this->providerFactory->sms($providerDoc);

        $this->assertInstanceOf(Bird::class, $adapter);
        $this->assertSame('Bird', $adapter->getName());
        $this->assertSame(100, $adapter->getMaxMessagesPerRequest());
    }

    public function testProviderFactoryReturnsNullWhenApiKeyMissing(): void
    {
        $providerDoc = new Document([
            'provider' => 'bird',
            'credentials' => [],
            'options' => [
                'from' => '+16175551212',
            ],
        ]);

        $adapter = $this->providerFactory->sms($providerDoc);

        $this->assertNull($adapter);
    }

    public function testProviderFactoryInstantiatesBirdEmailAdapter(): void
    {
        $providerDoc = new Document([
            'provider' => 'bird',
            'credentials' => [
                'apiKey' => 'bk_eu1_mock_payload_checksum',
            ],
            'options' => [
                'fromName' => 'Sender',
                'fromEmail' => 'sender@my-domain.com',
            ],
        ]);

        $adapter = $this->providerFactory->email($providerDoc);

        $this->assertInstanceOf(BirdEmail::class, $adapter);
        $this->assertSame('Bird', $adapter->getName());
        $this->assertSame('email', $adapter->getType());
        $this->assertSame(100, $adapter->getMaxMessagesPerRequest());
    }

    public function testProviderFactoryReturnsNullWhenBirdEmailApiKeyMissing(): void
    {
        $providerDoc = new Document([
            'provider' => 'bird',
            'credentials' => [],
            'options' => [
                'fromEmail' => 'sender@my-domain.com',
            ],
        ]);

        $this->assertNull($this->providerFactory->email($providerDoc));
    }
}
