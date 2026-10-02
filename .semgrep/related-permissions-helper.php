<?php

class RelatedPermissionsFixture
{
    public function bad($relation, $authorization): void
    {
        // ruleid: php.appwrite.related-permissions-helper
        $permissions = $relation->getAttribute('$permissions');
        foreach ($permissions as $permission) {
            $authorization->hasRole($permission);
        }
    }

    public function alsoBad(array $relation): void
    {
        // ruleid: php.appwrite.related-permissions-helper
        $permissions = $relation['$permissions'] ?? null;
        $this->writeRelated($permissions);
    }

    public function good($relation, $current, $authorization): void
    {
        // ok: php.appwrite.related-permissions-helper
        $this->validateRelatedPermissions($relation->getAttribute('$permissions'), $current, $authorization);
    }

    public function parentPermissions(array $data, array $operation): void
    {
        // ok: php.appwrite.related-permissions-helper
        $data['$permissions'] = [];
        // ok: php.appwrite.related-permissions-helper
        $permissions = $operation['data']['$permissions'];
    }
}
