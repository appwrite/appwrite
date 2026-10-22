<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Tasks;

use Appwrite\Auth\EncryptionKey;
use Appwrite\Platform\Tasks\Install;
use PHPUnit\Framework\TestCase;
use Utopia\Config\Config;

final class InstallTest extends TestCase
{
    /**
     * Every secret the stack trusts on its internal network, each shipped as
     * the public placeholder. A fresh install must replace all of them: the
     * executor one alone lets anything on that network create containers.
     */
    private const array SECRETS = ['_APP_OPENSSL_KEY_V1', '_APP_JOBS_SECRET', '_APP_EXECUTOR_SECRET'];

    public function testFreshInstallGeneratesEveryInternalSecret(): void
    {
        $input = (new Install())->prepareEnvironmentVariables([], $this->variables(), true);

        $generated = [];
        foreach (self::SECRETS as $name) {
            $this->assertArrayHasKey($name, $input);
            $this->assertFalse(EncryptionKey::isInsecure($input[$name]), "{$name} must not keep the shipped placeholder");
            $generated[] = $input[$name];
        }

        $this->assertSame($generated, \array_values(\array_unique($generated)), 'Each secret must be generated independently');
    }

    public function testUpgradeKeepsTheInstalledSecrets(): void
    {
        $installed = [];
        foreach (self::SECRETS as $name) {
            $installed[$name] = "installed-{$name}";
        }

        $input = (new Install())->prepareEnvironmentVariables($installed, $this->variables(), false);

        foreach (self::SECRETS as $name) {
            $this->assertSame($installed[$name], $input[$name]);
        }
    }

    /**
     * @return array<string, array<string, mixed>> The variables config keyed by name, as the install task reads it
     */
    private function variables(): array
    {
        $vars = [];
        foreach (Config::getParam('variables', []) as $category) {
            foreach ($category['variables'] ?? [] as $var) {
                $vars[$var['name']] = $var;
            }
        }

        return $vars;
    }
}
