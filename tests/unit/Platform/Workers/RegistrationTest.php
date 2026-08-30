<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Workers;

use Appwrite\Platform\Modules\Databases\Services\Workers as DatabasesWorkers;
use Appwrite\Platform\Modules\Functions\Services\Workers as FunctionsWorkers;
use Appwrite\Platform\Modules\Usage\Services\Workers as UsageWorkers;
use Appwrite\Platform\Services\Workers;
use Appwrite\Platform\Workers\Executions;
use Appwrite\Platform\Workers\Mails;
use Appwrite\Platform\Workers\Notifications;
use PHPUnit\Framework\TestCase;
use Utopia\Config\Config;

final class RegistrationTest extends TestCase
{
    public function testMailsAndNotificationsWorkersAreRegisteredSeparately(): void
    {
        $service = new Workers();

        $this->assertInstanceOf(Mails::class, $service->getAction('mails'));
        $this->assertInstanceOf(Notifications::class, $service->getAction('notifications'));
    }

    public function testExecutionsWorkerIsRegistered(): void
    {
        $service = new Workers();

        $this->assertInstanceOf(Executions::class, $service->getAction('executions'));
    }

    public function testRegisteredWorkerNamesMatchConfigWithoutDuplicates(): void
    {
        $names = [];
        foreach ([new Workers(), new DatabasesWorkers(), new FunctionsWorkers(), new UsageWorkers()] as $service) {
            foreach ($service->getActions() as $key => $action) {
                $name = \strtolower((string) $key);
                $this->assertArrayNotHasKey($name, $names, "Duplicate worker action '{$name}'");
                $names[$name] = true;
            }
        }

        $registered = \array_keys($names);
        \sort($registered);
        $expected = \array_keys(Config::getParam('workers'));
        \sort($expected);

        $this->assertSame($expected, $registered);
        $this->assertSame(1, Config::getParam('workers')['databases']['maxCoroutines']);
        $this->assertSame(8, Config::getParam('workers')['stats-usage']['maxCoroutines']);
    }
}
