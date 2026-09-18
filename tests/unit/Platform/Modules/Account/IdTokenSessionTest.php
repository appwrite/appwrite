<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Account;

use Appwrite\Platform\Modules\Account\Http\Account\Sessions\IdToken\Create;
use PHPUnit\Framework\TestCase;
use Utopia\Config\Config;

final class IdTokenSessionTest extends TestCase
{
    public function testProviderAcceptsOnlyIdTokenProviders(): void
    {
        $providers = Config::getParam('oAuthProviders', []);
        $expected = \array_keys(\array_filter($providers, fn (array $node): bool => !empty($node['idToken'])));

        $parameter = (new Create())->getParams()['provider'];
        $validator = $parameter['validator'];
        $validator = \is_callable($validator) ? $validator() : $validator;

        $this->assertSame($expected, $validator->getList());
        $this->assertNotContains('amazon', $validator->getList());
        $this->assertStringContainsString('apple, google', $parameter['description']);
        $this->assertSame('IdTokenProvider', $parameter['enum']->name);
    }
}
