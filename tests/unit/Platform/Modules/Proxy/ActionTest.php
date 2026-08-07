<?php

namespace Tests\Unit\Platform\Modules\Proxy;

use Appwrite\Platform\Modules\Proxy\Action;
use PHPUnit\Framework\TestCase;

class ActionTest extends TestCase
{
    protected string|false $functionsDomains = false;
    protected string|false $sitesDomains = false;
    protected string|false $denyListDomains = false;

    protected function setUp(): void
    {
        $this->functionsDomains = \getenv('_APP_DOMAIN_FUNCTIONS');
        $this->sitesDomains = \getenv('_APP_DOMAIN_SITES');
        $this->denyListDomains = \getenv('_APP_CUSTOM_DOMAIN_DENY_LIST');
    }

    protected function tearDown(): void
    {
        $this->restoreEnv('_APP_DOMAIN_FUNCTIONS', $this->functionsDomains);
        $this->restoreEnv('_APP_DOMAIN_SITES', $this->sitesDomains);
        $this->restoreEnv('_APP_CUSTOM_DOMAIN_DENY_LIST', $this->denyListDomains);
    }

    public function testValidateDomainRestrictionsIgnoresEmptyFunctionDomainEntries(): void
    {
        \putenv('_APP_DOMAIN_FUNCTIONS=functions.example.com,');
        \putenv('_APP_DOMAIN_SITES=');
        \putenv('_APP_CUSTOM_DOMAIN_DENY_LIST=');

        $action = new class extends Action
        {
            public function validate(string $domain, array $platform): void
            {
                $this->validateDomainRestrictions($domain, $platform);
            }
        };

        $action->validate('test.functions.example.com', ['hostnames' => []]);

        $this->addToAssertionCount(1);
    }

    protected function restoreEnv(string $name, string|false $value): void
    {
        if ($value === false) {
            \putenv($name);
            return;
        }

        \putenv($name . '=' . $value);
    }
}
