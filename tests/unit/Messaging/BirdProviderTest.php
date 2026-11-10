<?php

declare(strict_types=1);

namespace Tests\Unit\Messaging;

require_once __DIR__ . '/../../../app/init/constants.php';

if (!\class_exists('Swoole\Http\Response')) {
    eval('namespace Swoole\Http; class Response {}');
}

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Messaging\Provider;
use Appwrite\Network\Validator\PublicHostname;
use Appwrite\Platform\Modules\Messaging\Http\Providers\Bird\Create as CreateBirdProvider;
use Appwrite\Platform\Modules\Messaging\Http\Providers\Bird\Update as UpdateBirdProvider;
use Appwrite\Utopia\Response;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Network\FixedLookup;
use Utopia\Client\Destinations\PublicInternet;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Messaging\Adapter\SMS\Bird;
use Utopia\Platform\Action;
use Utopia\Telemetry\Adapter\None as NoneTelemetry;

final class BirdProviderTest extends TestCase
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

    public function testCreateBirdProviderActionMetadata(): void
    {
        $action = new CreateBirdProvider();

        $this->assertSame('createBirdProvider', CreateBirdProvider::getName());
        $this->assertContains(Action::HTTP_REQUEST_METHOD_POST, $action->getHttpMethods());
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
        $this->assertContains(Action::HTTP_REQUEST_METHOD_PATCH, $action->getHttpMethods());
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

    public function testCreateActionDisablesProviderWhenCredentialsAreMissing(): void
    {
        $action = new CreateBirdProvider();
        $db = $this->createMock(Database::class);
        $event = $this->createMock(Event::class);
        $response = $this->createMock(Response::class);

        $db->expects($this->once())
            ->method('createDocument')
            ->with('providers', $this->callback(function (Document $document) {
                $this->assertSame('bird', $document->getAttribute('provider'));
                $this->assertFalse($document->getAttribute('enabled'));
                $this->assertEmpty($document->getAttribute('credentials'));
                return true;
            }))
            ->willReturn(new Document(['$id' => 'bird_prov_1']));

        $event->expects($this->once())
            ->method('setParam')
            ->with('providerId', 'bird_prov_1')
            ->willReturnSelf();

        $response->expects($this->once())
            ->method('setStatusCode')
            ->with(Response::STATUS_CODE_CREATED)
            ->willReturnSelf();

        $response->expects($this->once())
            ->method('dynamic')
            ->with($this->isInstanceOf(Document::class), Response::MODEL_PROVIDER);

        $action->action('bird_prov_1', 'Bird SMS', '+16175551212', '', true, $event, $db, $response);
    }

    public function testCreateActionEnablesProviderWhenCredentialsAndOptionsProvided(): void
    {
        $action = new CreateBirdProvider();
        $db = $this->createMock(Database::class);
        $event = $this->createMock(Event::class);
        $response = $this->createMock(Response::class);

        $db->expects($this->once())
            ->method('createDocument')
            ->with('providers', $this->callback(function (Document $document) {
                $this->assertSame('bird', $document->getAttribute('provider'));
                $this->assertTrue($document->getAttribute('enabled'));
                $this->assertSame('test_api_key', $document->getAttribute('credentials')['apiKey']);
                $this->assertSame('+16175551212', $document->getAttribute('options')['from']);
                return true;
            }))
            ->willReturn(new Document(['$id' => 'bird_prov_2']));

        $event->expects($this->once())
            ->method('setParam')
            ->with('providerId', 'bird_prov_2')
            ->willReturnSelf();

        $response->expects($this->once())
            ->method('setStatusCode')
            ->with(Response::STATUS_CODE_CREATED)
            ->willReturnSelf();

        $response->expects($this->once())
            ->method('dynamic')
            ->with($this->isInstanceOf(Document::class), Response::MODEL_PROVIDER);

        $action->action('bird_prov_2', 'Bird SMS', '+16175551212', 'test_api_key', true, $event, $db, $response);
    }

    public function testUpdateActionThrowsWhenEnablingWithoutCredentials(): void
    {
        $action = new UpdateBirdProvider();
        $db = $this->createMock(Database::class);
        $event = $this->createStub(Event::class);
        $response = $this->createStub(Response::class);

        $existingProvider = new Document([
            '$id' => 'bird_prov_3',
            'provider' => 'bird',
            'credentials' => [],
            'options' => ['from' => '+16175551212'],
        ]);

        $db->expects($this->once())
            ->method('getDocument')
            ->with('providers', 'bird_prov_3')
            ->willReturn($existingProvider);

        $this->expectException(Exception::class);
        $this->expectExceptionCode(400);

        $action->action('bird_prov_3', '', true, '', '', $event, $db, $response);
    }

    public function testUpdateActionPersistsSparseDocument(): void
    {
        $action = new UpdateBirdProvider();
        $db = $this->createMock(Database::class);
        $event = $this->createMock(Event::class);
        $response = $this->createMock(Response::class);

        $existingProvider = new Document([
            '$id' => 'bird_prov_4',
            'name' => 'Old Name',
            'provider' => 'bird',
            'enabled' => false,
            'credentials' => ['apiKey' => 'existing_key'],
            'options' => ['from' => '+16175551212'],
        ]);

        $db->expects($this->once())
            ->method('getDocument')
            ->with('providers', 'bird_prov_4')
            ->willReturn($existingProvider);

        $db->expects($this->once())
            ->method('updateDocument')
            ->with('providers', 'bird_prov_4', $this->callback(function (Document $sparseDoc) {
                $array = $sparseDoc->getArrayCopy();
                $this->assertArrayHasKey('name', $array);
                $this->assertSame('New Name', $array['name']);
                $this->assertArrayNotHasKey('credentials', $array);
                $this->assertArrayNotHasKey('options', $array);
                $this->assertArrayNotHasKey('enabled', $array);
                return true;
            }))
            ->willReturn(new Document(['$id' => 'bird_prov_4', 'name' => 'New Name']));

        $event->expects($this->once())
            ->method('setParam')
            ->with('providerId', 'bird_prov_4')
            ->willReturnSelf();

        $response->expects($this->once())
            ->method('dynamic')
            ->with($this->isInstanceOf(Document::class), Response::MODEL_PROVIDER);

        $action->action('bird_prov_4', 'New Name', null, '', '', $event, $db, $response);
    }

    public function testUpdateActionEnablesAndUpdatesCredentialsSparse(): void
    {
        $action = new UpdateBirdProvider();
        $db = $this->createMock(Database::class);
        $event = $this->createMock(Event::class);
        $response = $this->createMock(Response::class);

        $existingProvider = new Document([
            '$id' => 'bird_prov_5',
            'name' => 'Bird Provider',
            'provider' => 'bird',
            'enabled' => false,
            'credentials' => [],
            'options' => ['from' => '+16175551212'],
        ]);

        $db->expects($this->once())
            ->method('getDocument')
            ->with('providers', 'bird_prov_5')
            ->willReturn($existingProvider);

        $db->expects($this->once())
            ->method('updateDocument')
            ->with('providers', 'bird_prov_5', $this->callback(function (Document $sparseDoc) {
                $array = $sparseDoc->getArrayCopy();
                $this->assertArrayHasKey('credentials', $array);
                $this->assertSame('updated_key', $array['credentials']['apiKey']);
                $this->assertArrayHasKey('enabled', $array);
                $this->assertTrue($array['enabled']);
                $this->assertArrayNotHasKey('name', $array);
                $this->assertArrayNotHasKey('options', $array);
                return true;
            }))
            ->willReturn(new Document(['$id' => 'bird_prov_5']));

        $event->expects($this->once())
            ->method('setParam')
            ->with('providerId', 'bird_prov_5')
            ->willReturnSelf();

        $response->expects($this->once())
            ->method('dynamic')
            ->with($this->isInstanceOf(Document::class), Response::MODEL_PROVIDER);

        $action->action('bird_prov_5', '', true, 'updated_key', '', $event, $db, $response);
    }
}
