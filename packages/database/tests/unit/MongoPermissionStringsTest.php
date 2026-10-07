<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Utopia\Database\Adapter\Mongo;
use Utopia\Database\Database;
use Utopia\Database\Validator\Authorization;

class MongoPermissionStringsTest extends TestCase
{
    public function testPeriodInRoleIsLiteralNotRegexWildcard(): void
    {
        $this->assertSame(
            ['read("user:alice.")'],
            $this->permissionStrings(['user:alice.'], Database::PERMISSION_READ)
        );
    }

    public function testMassReadDotPaddingStaysExact(): void
    {
        $role = 'user:a' . \str_repeat('.', 19);

        $this->assertSame(
            ['read("' . $role . '")'],
            $this->permissionStrings([$role], Database::PERMISSION_READ)
        );
    }

    public function testMatchingIsCaseSensitiveAndUsesRequestedType(): void
    {
        $this->assertSame(
            ['update("user:alice")'],
            $this->permissionStrings(['user:alice'], Database::PERMISSION_UPDATE)
        );
    }

    public function testMultipleRolesMapToExactPermissionStrings(): void
    {
        $this->assertSame(
            ['read("user:alice")', 'read("users")'],
            $this->permissionStrings(['user:alice', 'users'], Database::PERMISSION_READ)
        );
    }

    public function testEmptyRolesProduceEmptyList(): void
    {
        $this->assertSame([], $this->permissionStrings([], Database::PERMISSION_READ));
    }

    public function testValuesAreStringsNotRegex(): void
    {
        foreach ($this->permissionStrings(['user:alice.'], Database::PERMISSION_READ) as $value) {
            $this->assertIsString($value);
            $this->assertStringStartsWith('read("', $value);
            $this->assertStringEndsWith('")', $value);
        }
    }

    /**
     * @param list<string> $roles
     * @return list<string>
     */
    private function permissionStrings(array $roles, string $type): array
    {
        $authorization = new Authorization();
        $authorization->enable();
        $authorization->cleanRoles();
        foreach ($roles as $role) {
            $authorization->addRole($role);
        }

        $adapter = (new ReflectionClass(Mongo::class))->newInstanceWithoutConstructor();
        $adapter->setAuthorization($authorization);

        $method = new ReflectionMethod(Mongo::class, 'permissionStrings');

        /** @var list<string> $values */
        $values = $method->invoke($adapter, $type);

        return $values;
    }
}
