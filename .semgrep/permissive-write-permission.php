<?php

use Utopia\Database\Permission;
use Utopia\Database\Role;

$permissions = [
    // ok: php.appwrite.permissive-write-permission
    Permission::read(Role::any()),
    // ruleid: php.appwrite.permissive-write-permission
    Permission::update(Role::any()),
    // ruleid: php.appwrite.permissive-write-permission
    Permission::delete(Role::guests()),
    // ruleid: php.appwrite.permissive-write-permission
    Permission::write(Role::any()),
    // ok: php.appwrite.permissive-write-permission
    Permission::update(Role::user($userId)),
    // ok: php.appwrite.permissive-write-permission
    Permission::delete(Role::team($teamId, 'owner')),
];
