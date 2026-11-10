<?php

namespace Tests\Unit\Tokens;

use Appwrite\Event\Event;
use Appwrite\Platform\Modules\Tokens\Http\Tokens\Buckets\Files\Create;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model\ResourceToken;
use PHPUnit\Framework\TestCase;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Permission;
use Utopia\Database\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Validator\Nullable;

class FileTokenMaxUsesTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!\class_exists('Swoole\Http\Response')) {
            eval('namespace Swoole\Http; class Response {}');
        }

        require_once __DIR__ . '/../../../app/init/constants.php';
    }

    protected function setUp(): void
    {
        $_ENV['_APP_OPENSSL_KEY_V1'] = 'your-secret-key-1234567890123456';
        \putenv('_APP_OPENSSL_KEY_V1=your-secret-key-1234567890123456');
        Config::setParam('errors', include __DIR__ . '/../../../app/config/errors.php');
    }

    public function testCreateActionHasMaxUsesParameter(): void
    {
        $action = new Create();
        $params = $action->getParams();

        $this->assertArrayHasKey('maxUses', $params);
        $maxUsesParam = $params['maxUses'];

        $this->assertNull($maxUsesParam['default']);
        $this->assertTrue($maxUsesParam['optional']);
        $this->assertInstanceOf(Nullable::class, $maxUsesParam['validator']);

        $validator = $maxUsesParam['validator'];
        $this->assertTrue($validator->isValid(null));
        $this->assertTrue($validator->isValid(1));
        $this->assertTrue($validator->isValid(100));
        $this->assertFalse($validator->isValid(0));
        $this->assertFalse($validator->isValid(-1));
        $this->assertFalse($validator->isValid('invalid'));
    }

    public function testResourceTokenModelRules(): void
    {
        $model = new ResourceToken();
        $rules = $model->getRules();

        $this->assertArrayHasKey('maxUses', $rules);
        $this->assertSame(ResourceToken::TYPE_INTEGER, $rules['maxUses']['type']);
        $this->assertNull($rules['maxUses']['default']);
        $this->assertFalse($rules['maxUses']['required']);

        $this->assertArrayHasKey('uses', $rules);
        $this->assertSame(ResourceToken::TYPE_INTEGER, $rules['uses']['type']);
        $this->assertSame(0, $rules['uses']['default']);
        $this->assertFalse($rules['uses']['required']);
    }

    public function testResourceTokenModelPayloadWithMaxUses(): void
    {
        $model = new ResourceToken();

        $tokenDoc = new Document([
            '$id' => 'token123',
            '$createdAt' => '2026-11-10T12:00:00.000+00:00',
            'resourceId' => 'bucket1:file1',
            'resourceType' => 'files',
            'resourceInternalId' => '1:1',
            'expire' => null,
            'secret' => 'raw-secret',
            'accessedAt' => '2026-11-10T12:00:00.000+00:00',
            'maxUses' => 3,
            'uses' => 0,
        ]);

        $filtered = $model->filter($tokenDoc);

        $this->assertSame(3, $filtered->getAttribute('maxUses'));
        $this->assertSame(0, $filtered->getAttribute('uses'));
        $this->assertNotEmpty($filtered->getAttribute('secret'));
    }

    public function testResourceTokenModelPayloadWithoutMaxUses(): void
    {
        $model = new ResourceToken();

        $tokenDoc = new Document([
            '$id' => 'token456',
            '$createdAt' => '2026-11-10T12:00:00.000+00:00',
            'resourceId' => 'bucket1:file1',
            'resourceType' => 'files',
            'resourceInternalId' => '1:1',
            'expire' => null,
            'secret' => 'raw-secret',
            'accessedAt' => '2026-11-10T12:00:00.000+00:00',
            'maxUses' => null,
            'uses' => 0,
        ]);

        $filtered = $model->filter($tokenDoc);

        $this->assertNull($filtered->getAttribute('maxUses'));
        $this->assertSame(0, $filtered->getAttribute('uses'));
    }

    public function testCreateActionPersistsMaxUsesAndInitializesUses(): void
    {
        $action = new Create();
        $db = $this->createMock(Database::class);
        $response = $this->createMock(Response::class);
        $queueForEvents = $this->createMock(Event::class);
        $authorization = new Authorization();
        $user = new User(['$id' => 'user_1']);

        $bucket = new Document([
            '$id' => 'b1',
            '$sequence' => '1',
            'enabled' => true,
            'fileSecurity' => false,
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
        ]);

        $file = new Document([
            '$id' => 'f1',
            '$sequence' => '1',
            'bucketInternalId' => '1',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
        ]);

        $db->expects($this->exactly(2))
            ->method('getDocument')
            ->willReturnMap([
                ['buckets', 'b1', [], $bucket],
                ['bucket_1', 'f1', [], $file],
            ]);

        $db->expects($this->once())
            ->method('createDocument')
            ->with(
                'resourceTokens',
                $this->callback(function (Document $doc) {
                    return $doc->getAttribute('maxUses') === 5
                        && $doc->getAttribute('uses') === 0
                        && $doc->getAttribute('resourceId') === 'b1:f1'
                        && $doc->getAttribute('resourceType') === TOKENS_RESOURCE_TYPE_FILES;
                })
            )
            ->willReturnCallback(function (string $collection, Document $doc) {
                return $doc;
            });

        $queueForEvents->expects($this->exactly(3))
            ->method('setParam')
            ->willReturnSelf();

        $queueForEvents->expects($this->once())
            ->method('setContext')
            ->willReturnSelf();

        $response->expects($this->once())
            ->method('setStatusCode')
            ->with(201)
            ->willReturnSelf();

        $response->expects($this->once())
            ->method('dynamic')
            ->with($this->isInstanceOf(Document::class), Response::MODEL_RESOURCE_TOKEN);

        $action->action('b1', 'f1', null, 5, $response, $user, $db, $queueForEvents, $authorization);
    }
}
