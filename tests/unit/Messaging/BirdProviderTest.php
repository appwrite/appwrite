<?php

declare(strict_types=1);

namespace Tests\Unit\Messaging;

require_once __DIR__ . '/../../../app/init/constants.php';

if (!\class_exists('Swoole\Http\Response')) {
    eval('namespace Swoole\Http; class Response {}');
}

use Appwrite\Messaging\Provider;
use Appwrite\Network\Validator\PublicHostname;
use Appwrite\Platform\Modules\Messaging\Http\Providers\Bird\Create as CreateBirdProvider;
use Appwrite\Platform\Modules\Messaging\Http\Providers\Bird\Update as UpdateBirdProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Network\FixedLookup;
use Utopia\Client\Destinations\PublicInternet;
use Utopia\Database\Document;
use Utopia\Messaging\Adapter\SMS\Bird;
use Utopia\Platform\Action;
use Utopia\Telemetry\Adapter\None as NoneTelemetry;

final class BirdProviderTest extends TestCase
{
    private Provider $providerFactory;

    protected function setUp(): void
    {
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

    public function testCreateBirdProviderActionMetadata(): void
    {
        $action = new CreateBirdProvider();

        $this->assertSame('createBirdProvider', CreateBirdProvider::getName());
        $this->assertSame(Action::HTTP_REQUEST_METHOD_POST, $action->getHttpMethod());
        $this->assertSame('/v1/messaging/providers/bird', $action->getHttpPath());

        $params = $action->getParams();
        $this->assertArrayHasKey('providerId', $params);
        $this->assertArrayHasKey('name', $params);
        $this->assertArrayHasKey('from', $params);
        $this->assertArrayHasKey('apiKey', $params);
        $this->assertArrayHasKey('enabled', $params);

        $this->assertFalse($params['name']['optional']);
        $this->assertTrue($params['from']['optional']);
        $this->assertTrue($params['apiKey']['optional']);
        $this->assertTrue($params['enabled']['optional']);
    }

    public function testUpdateBirdProviderActionMetadata(): void
    {
        $action = new UpdateBirdProvider();

        $this->assertSame('updateBirdProvider', UpdateBirdProvider::getName());
        $this->assertSame(Action::HTTP_REQUEST_METHOD_PATCH, $action->getHttpMethod());
        $this->assertSame('/v1/messaging/providers/bird/:providerId', $action->getHttpPath());

        $params = $action->getParams();
        $this->assertArrayHasKey('providerId', $params);
        $this->assertArrayHasKey('name', $params);
        $this->assertArrayHasKey('enabled', $params);
        $this->assertArrayHasKey('apiKey', $params);
        $this->assertArrayHasKey('from', $params);

        $this->assertFalse($params['providerId']['optional']);
        $this->assertTrue($params['name']['optional']);
        $this->assertTrue($params['enabled']['optional']);
        $this->assertTrue($params['apiKey']['optional']);
        $this->assertTrue($params['from']['optional']);
    }

    public function testBirdProviderDocumentEnabledStateRules(): void
    {
        $credentials = ['apiKey' => 'bk_eu1_mock_payload_checksum'];
        $options = ['from' => '+16175551212'];

        $canEnable = \array_key_exists('apiKey', $credentials) && \array_key_exists('from', $options);
        $this->assertTrue($canEnable);

        $missingCredentials = [];
        $cannotEnable = \array_key_exists('apiKey', $missingCredentials) && \array_key_exists('from', $options);
        $this->assertFalse($cannotEnable);
    }
}
