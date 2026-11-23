<?php

declare(strict_types=1);

namespace Utopia\Distribution\Tests;

use Utopia\Distribution\Account;
use Utopia\Distribution\App;
use Utopia\Distribution\Store;
use Utopia\Psr7\Request\Factory;

final class Unsupported extends Store
{
    public const string NAME = 'unsupported';

    #[\Override]
    public function getName(): string
    {
        return self::NAME;
    }

    #[\Override]
    public function account(): Account
    {
        $this->send(new Factory()->createRequest('GET', "{$this->endpoint}/account"));

        return new Account('account');
    }

    #[\Override]
    public function app(string $identifier): App
    {
        return new App('app', $identifier);
    }

    #[\Override]
    public function latestVersionCode(string $identifier): int
    {
        return 0;
    }
}
