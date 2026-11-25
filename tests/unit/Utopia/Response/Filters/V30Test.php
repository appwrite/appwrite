<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Response\Filters;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Filters\V30;
use PHPUnit\Framework\TestCase;

final class V30Test extends TestCase
{
    public function testHidesLdapAuthMethodFromProject(): void
    {
        $filter = new V30();

        $result = $filter->parse([
            '$id' => 'project',
            'authMethods' => [
                ['$id' => 'email-password', 'enabled' => true],
                ['$id' => 'ldap', 'enabled' => false],
                ['$id' => 'passkey', 'enabled' => true],
            ],
        ], Response::MODEL_PROJECT);

        $this->assertSame([
            '$id' => 'project',
            'authMethods' => [
                ['$id' => 'email-password', 'enabled' => true],
                ['$id' => 'passkey', 'enabled' => true],
            ],
        ], $result);
    }

    public function testHidesLdapAuthMethodFromProjectList(): void
    {
        $filter = new V30();

        $result = $filter->parse([
            'total' => 1,
            'projects' => [[
                '$id' => 'project',
                'authMethods' => [
                    ['$id' => 'ldap', 'enabled' => true],
                    ['$id' => 'anonymous', 'enabled' => true],
                ],
            ]],
        ], Response::MODEL_PROJECT_LIST);

        $this->assertSame([
            'total' => 1,
            'projects' => [[
                '$id' => 'project',
                'authMethods' => [
                    ['$id' => 'anonymous', 'enabled' => true],
                ],
            ]],
        ], $result);
    }

    public function testRestoresCriticalOnMonitorsAndAlerts(): void
    {
        $filter = new V30();

        $this->assertSame(
            ['$id' => 'monitor', 'severity' => 'critical', 'critical' => true],
            $filter->parse(['$id' => 'monitor', 'severity' => 'critical'], Response::MODEL_MONITOR),
        );
        $this->assertSame(
            ['$id' => 'alert', 'severity' => 'error', 'critical' => false],
            $filter->parse(['$id' => 'alert', 'severity' => 'error'], Response::MODEL_ALERT),
        );
        $this->assertSame(
            ['total' => 2, 'monitors' => [
                ['$id' => 'first', 'severity' => 'warning', 'critical' => false],
                ['$id' => 'second', 'severity' => 'critical', 'critical' => true],
            ]],
            $filter->parse(['total' => 2, 'monitors' => [
                ['$id' => 'first', 'severity' => 'warning'],
                ['$id' => 'second', 'severity' => 'critical'],
            ]], Response::MODEL_MONITOR_LIST),
        );
        $this->assertSame(
            ['total' => 1, 'alerts' => [['$id' => 'alert', 'severity' => 'critical', 'critical' => true]]],
            $filter->parse(['total' => 1, 'alerts' => [['$id' => 'alert', 'severity' => 'critical']]], Response::MODEL_ALERT_LIST),
        );
    }

    public function testLeavesOtherModelsAlone(): void
    {
        $filter = new V30();

        $content = ['$id' => 'user', 'authMethods' => [['$id' => 'ldap']]];

        $this->assertSame($content, $filter->parse($content, Response::MODEL_USER));
    }
}
