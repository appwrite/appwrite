<?php

namespace Tests\Unit\Tokens;

use Appwrite\Platform\Modules\Tokens\Http\Tokens\Buckets\Files\Create;
use Appwrite\Utopia\Response\Model\ResourceToken;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;
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

    public function testTokenExpirationOnMaxUsesExhaustion(): void
    {
        $isTokenValid = function (Document $token): bool {
            $maxUses = $token->getAttribute('maxUses');
            $uses = $token->getAttribute('uses', 0);

            if ($maxUses !== null && $uses >= $maxUses) {
                return false;
            }

            return true;
        };

        // Single-use token unused
        $token1 = new Document(['maxUses' => 1, 'uses' => 0]);
        $this->assertTrue($isTokenValid($token1));

        // Single-use token used once -> expired
        $token1->setAttribute('uses', 1);
        $this->assertFalse($isTokenValid($token1));

        // Multi-use token (3 uses)
        $token3 = new Document(['maxUses' => 3, 'uses' => 0]);
        $this->assertTrue($isTokenValid($token3));

        $token3->setAttribute('uses', 1);
        $this->assertTrue($isTokenValid($token3));

        $token3->setAttribute('uses', 2);
        $this->assertTrue($isTokenValid($token3));

        $token3->setAttribute('uses', 3);
        $this->assertFalse($isTokenValid($token3));

        $token3->setAttribute('uses', 4);
        $this->assertFalse($isTokenValid($token3));
    }

    public function testTokenWithoutMaxUsesNeverExpiresFromUses(): void
    {
        $isTokenValid = function (Document $token): bool {
            $maxUses = $token->getAttribute('maxUses');
            $uses = $token->getAttribute('uses', 0);

            if ($maxUses !== null && $uses >= $maxUses) {
                return false;
            }

            return true;
        };

        $unlimitedToken = new Document(['maxUses' => null, 'uses' => 0]);
        $this->assertTrue($isTokenValid($unlimitedToken));

        $unlimitedToken->setAttribute('uses', 100);
        $this->assertTrue($isTokenValid($unlimitedToken));

        $unlimitedToken->setAttribute('uses', 1_000_000);
        $this->assertTrue($isTokenValid($unlimitedToken));
    }

    public function testSequentialTokenUsageIncrement(): void
    {
        $token = new Document([
            '$id' => 'token_seq',
            'maxUses' => 2,
            'uses' => 0,
        ]);

        $this->assertSame(0, $token->getAttribute('uses'));
        $this->assertSame(2, $token->getAttribute('maxUses'));

        // Simulate 1st use increment
        $token->setAttribute('uses', $token->getAttribute('uses', 0) + 1);
        $this->assertSame(1, $token->getAttribute('uses'));
        $this->assertLessThan($token->getAttribute('maxUses'), $token->getAttribute('uses'));

        // Simulate 2nd use increment
        $token->setAttribute('uses', $token->getAttribute('uses', 0) + 1);
        $this->assertSame(2, $token->getAttribute('uses'));
        $this->assertGreaterThanOrEqual($token->getAttribute('maxUses'), $token->getAttribute('uses'));
    }
}
