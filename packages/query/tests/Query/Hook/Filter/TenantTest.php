<?php

namespace Tests\Query\Hook\Filter;

use PHPUnit\Framework\TestCase;
use Tests\Query\Fixture\PermissionFilter as Permission;
use Utopia\Query\Hook\Filter\Tenant;

class TenantTest extends TestCase
{
    public function testTenantSingleId(): void
    {
        $hook = new Tenant(['t1']);
        $condition = $hook->filter('users');

        $this->assertSame('tenant_id IN (?)', $condition->expression);
        $this->assertSame(['t1'], $condition->bindings);
    }

    public function testTenantMultipleIds(): void
    {
        $hook = new Tenant(['t1', 't2', 't3']);
        $condition = $hook->filter('users');

        $this->assertSame('tenant_id IN (?, ?, ?)', $condition->expression);
        $this->assertSame(['t1', 't2', 't3'], $condition->bindings);
    }

    public function testTenantCustomColumn(): void
    {
        $hook = new Tenant(['t1'], 'organization_id');
        $condition = $hook->filter('users');

        $this->assertSame('organization_id IN (?)', $condition->expression);
        $this->assertSame(['t1'], $condition->bindings);
    }

    public function testPermissionWithRoles(): void
    {
        $hook = new Permission(
            roles: ['role:admin', 'role:user'],
            permissionsTable: fn (string $table) => "mydb_{$table}_perms",
        );
        $condition = $hook->filter('documents');

        $this->assertSame(
            'id IN (SELECT DISTINCT document_id FROM mydb_documents_perms WHERE role IN (?, ?) AND type = ?)',
            $condition->expression
        );
        $this->assertSame(['role:admin', 'role:user', 'read'], $condition->bindings);
    }

    public function testPermissionEmptyRoles(): void
    {
        $hook = new Permission(
            roles: [],
            permissionsTable: fn (string $table) => "mydb_{$table}_perms",
        );
        $condition = $hook->filter('documents');

        $this->assertSame('1 = 0', $condition->expression);
        $this->assertSame([], $condition->bindings);
    }

    public function testPermissionCustomType(): void
    {
        $hook = new Permission(
            roles: ['role:admin'],
            permissionsTable: fn (string $table) => "mydb_{$table}_perms",
            type: 'write',
        );
        $condition = $hook->filter('documents');

        $this->assertSame(
            'id IN (SELECT DISTINCT document_id FROM mydb_documents_perms WHERE role IN (?) AND type = ?)',
            $condition->expression
        );
        $this->assertSame(['role:admin', 'write'], $condition->bindings);
    }

    public function testPermissionCustomDocumentColumn(): void
    {
        $hook = new Permission(
            roles: ['role:admin'],
            permissionsTable: fn (string $table) => "mydb_{$table}_perms",
            documentColumn: 'doc_id',
        );
        $condition = $hook->filter('documents');

        $this->assertStringStartsWith('doc_id IN', $condition->expression);
    }

    public function testPermissionCustomColumns(): void
    {
        $hook = new Permission(
            roles: ['admin'],
            permissionsTable: fn (string $table) => 'acl',
            documentColumn: 'uid',
            permDocumentColumn: 'resource_id',
            permRoleColumn: 'principal',
            permTypeColumn: 'access',
        );
        $condition = $hook->filter('documents');

        $this->assertSame(
            'uid IN (SELECT DISTINCT resource_id FROM acl WHERE principal IN (?) AND access = ?)',
            $condition->expression
        );
        $this->assertSame(['admin', 'read'], $condition->bindings);
    }

    public function testPermissionStaticTable(): void
    {
        $hook = new Permission(
            roles: ['user:123'],
            permissionsTable: fn (string $table) => 'permissions',
        );
        $condition = $hook->filter('any_table');

        $this->assertSame('id IN (SELECT DISTINCT document_id FROM permissions WHERE role IN (?) AND type = ?)', $condition->expression);
    }

    public function testPermissionWithColumns(): void
    {
        $hook = new Permission(
            roles: ['role:admin'],
            permissionsTable: fn (string $table) => "mydb_{$table}_perms",
            columns: ['email', 'phone'],
        );
        $condition = $hook->filter('users');

        $this->assertSame(
            'id IN (SELECT DISTINCT document_id FROM mydb_users_perms WHERE role IN (?) AND type = ? AND (column IS NULL OR column IN (?, ?)))',
            $condition->expression
        );
        $this->assertSame(['role:admin', 'read', 'email', 'phone'], $condition->bindings);
    }

    public function testPermissionWithSingleColumn(): void
    {
        $hook = new Permission(
            roles: ['role:user'],
            permissionsTable: fn (string $table) => "{$table}_perms",
            columns: ['salary'],
        );
        $condition = $hook->filter('employees');

        $this->assertSame(
            'id IN (SELECT DISTINCT document_id FROM employees_perms WHERE role IN (?) AND type = ? AND (column IS NULL OR column IN (?)))',
            $condition->expression
        );
        $this->assertSame(['role:user', 'read', 'salary'], $condition->bindings);
    }

    public function testPermissionWithEmptyColumns(): void
    {
        $hook = new Permission(
            roles: ['role:admin'],
            permissionsTable: fn (string $table) => "mydb_{$table}_perms",
            columns: [],
        );
        $condition = $hook->filter('users');

        $this->assertSame(
            'id IN (SELECT DISTINCT document_id FROM mydb_users_perms WHERE role IN (?) AND type = ? AND column IS NULL)',
            $condition->expression
        );
        $this->assertSame(['role:admin', 'read'], $condition->bindings);
    }

    public function testPermissionWithoutColumnsOmitsClause(): void
    {
        $hook = new Permission(
            roles: ['role:admin'],
            permissionsTable: fn (string $table) => "mydb_{$table}_perms",
        );
        $condition = $hook->filter('users');

        $this->assertStringNotContainsString('column', $condition->expression);
    }

    public function testPermissionCustomColumnColumn(): void
    {
        $hook = new Permission(
            roles: ['role:admin'],
            permissionsTable: fn (string $table) => 'acl',
            columns: ['email'],
            permColumnColumn: 'field',
        );
        $condition = $hook->filter('users');

        $this->assertSame(
            'id IN (SELECT DISTINCT document_id FROM acl WHERE role IN (?) AND type = ? AND (field IS NULL OR field IN (?)))',
            $condition->expression
        );
        $this->assertSame(['role:admin', 'read', 'email'], $condition->bindings);
    }

    // ══════════════════════════════════════════════════════════════
    // Coverage: Permission.php uncovered lines
    // ══════════════════════════════════════════════════════════════

    // ── Invalid column name (line 36) ────────────────────────────

    public function testPermissionInvalidColumnNameThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid column name');
        new Permission(
            roles: ['admin'],
            permissionsTable: fn (string $table) => 'perms',
            documentColumn: '123bad',
        );
    }

    // ── Invalid permissions table name (line 51) ─────────────────

    public function testPermissionInvalidTableNameThrows(): void
    {
        $hook = new Permission(
            roles: ['admin'],
            permissionsTable: fn (string $table) => 'invalid table!',
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid permissions table name');
        $hook->filter('users');
    }

    // ── subqueryFilter (lines 72-74) ─────────────────────────────

    public function testPermissionWithSubqueryFilter(): void
    {
        $tenantFilter = new Tenant(['t1']);

        $hook = new Permission(
            roles: ['role:admin'],
            permissionsTable: fn (string $table) => 'perms',
            subqueryFilter: $tenantFilter,
        );
        $condition = $hook->filter('users');

        $this->assertSame('id IN (SELECT DISTINCT document_id FROM perms WHERE role IN (?) AND type = ? AND tenant_id IN (?))', $condition->expression);
        $this->assertContains('t1', $condition->bindings);
    }

    // ══════════════════════════════════════════════════════════════
    // Coverage: Tenant.php uncovered lines
    // ══════════════════════════════════════════════════════════════

    // ── Invalid column name (line 22) ────────────────────────────

    public function testTenantInvalidColumnNameThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid column name');
        new Tenant(['t1'], '123bad');
    }

    // ── Empty tenantIds (line 29) ────────────────────────────────

    public function testTenantEmptyTenantIdsReturnsNoMatch(): void
    {
        $hook = new Tenant([]);
        $condition = $hook->filter('users');

        $this->assertSame('1 = 0', $condition->expression);
        $this->assertSame([], $condition->bindings);
    }
}
