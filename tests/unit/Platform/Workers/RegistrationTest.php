<?php

namespace Tests\Unit\Platform\Workers;

use Appwrite\Platform\Services\Workers;
use Appwrite\Platform\Workers\Mails;
use Appwrite\Platform\Workers\Notifications;
use PHPUnit\Framework\TestCase;

class RegistrationTest extends TestCase
{
    public function testMailsAndNotificationsWorkersAreRegisteredSeparately(): void
    {
        $service = new Workers();

        $this->assertInstanceOf(Mails::class, $service->getAction('mails'));
        $this->assertInstanceOf(Notifications::class, $service->getAction('notifications'));
    }

    public function testEntrypointDoesNotAliasMailsToNotifications(): void
    {
        $contents = \file_get_contents(__DIR__ . '/../../../../app/worker.php');

        $this->assertIsString($contents);
        $this->assertStringNotContainsString("mails' ? 'notifications'", $contents);
        $this->assertStringContainsString('\'workerName\' => strtolower($workerName)', $contents);
    }
}
