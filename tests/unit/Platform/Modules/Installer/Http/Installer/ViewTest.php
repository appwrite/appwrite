<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Installer\Http\Installer;

use PHPUnit\Framework\TestCase;
use Throwable;

final class ViewTest extends TestCase
{
    public function testDatabaseOptionsFollowPreferredOrder(): void
    {
        $html = $this->render();
        $postgres = strpos($html, '>PostgreSQL</div>');
        $maria = strpos($html, '>MariaDB</div>');
        $mongo = strpos($html, '>MongoDB</div>');

        $this->assertNotFalse($postgres);
        $this->assertNotFalse($maria);
        $this->assertNotFalse($mongo);
        $this->assertLessThan($maria, $postgres);
        $this->assertLessThan($mongo, $maria);
    }

    public function testHttpsDefaultsToDisabled(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('data-default-force-https="false"', $html);
        $this->assertDoesNotMatchRegularExpression('/id="force-https"[^>]*checked/', $html);
    }

    public function testHttpsUsesExistingInstallerSetting(): void
    {
        $html = $this->render([
            'vars' => [
                '_APP_OPTIONS_FORCE_HTTPS' => ['default' => 'enabled'],
            ],
        ]);

        $this->assertStringContainsString('data-default-force-https="true"', $html);
        $this->assertMatchesRegularExpression('/id="force-https"[^>]*checked/', $html);
    }

    public function testUpgradeWithoutDetectedDatabaseRemainsSelectable(): void
    {
        $html = $this->render([
            'isUpgrade' => true,
            'lockedDatabase' => null,
        ]);

        $this->assertStringNotContainsString('data-locked-database=', $html);
        $this->assertStringNotContainsString('selector-group is-locked', $html);
        $this->assertDoesNotMatchRegularExpression('/<input type="radio" name="database"[^>]*disabled/', $html);
        $this->assertMatchesRegularExpression('/name="database" value="postgresql"[^>]*checked/', $html);
    }

    public function testUpgradeWithDetectedDatabaseRemainsLocked(): void
    {
        $html = $this->render([
            'isUpgrade' => true,
            'lockedDatabase' => 'mongodb',
        ]);

        $this->assertStringContainsString('data-locked-database="mongodb"', $html);
        $this->assertStringContainsString('selector-group is-locked', $html);
        $this->assertMatchesRegularExpression('/name="database" value="mongodb"[^>]*checked/', $html);
        $this->assertMatchesRegularExpression('/name="database" value="postgresql"[^>]*disabled/', $html);
    }

    public function testUpgradeWithDetectedSeparateTopologyPreselectsSeparate(): void
    {
        $html = $this->render([
            'isUpgrade' => true,
            'topology' => 'separate',
        ]);

        $this->assertStringContainsString('data-topology="separate"', $html);
        $this->assertMatchesRegularExpression('/name="topology" value="separate"[^>]*checked/', $html);
        $this->assertDoesNotMatchRegularExpression('/name="topology" value="combined"[^>]*checked/', $html);
    }

    private function render(array $values = []): string
    {
        $step = 1;
        $isUpgrade = false;
        $lockedDatabase = null;
        $topology = 'combined';
        $vars = [];
        $enabledDatabases = ['postgresql', 'mariadb', 'mongodb'];
        $csrfToken = 'test-token';

        extract($values, EXTR_OVERWRITE);

        ob_start();
        try {
            include dirname(__DIR__, 7) . '/app/views/install/installer.phtml';
            return (string) ob_get_clean();
        } catch (Throwable $error) {
            ob_end_clean();
            throw $error;
        }
    }
}
