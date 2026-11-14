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

    public function testLeavesOtherModelsAlone(): void
    {
        $filter = new V30();

        $content = ['$id' => 'user', 'authMethods' => [['$id' => 'ldap']]];

        $this->assertSame($content, $filter->parse($content, Response::MODEL_USER));
    }
}
