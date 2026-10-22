<?php

class RelatedPermissionsFixture
{
    public function badRead($relation, $authorization): void
    {
        // ruleid: php.appwrite.related-permissions-helper
        $permissions = $relation->getAttribute('$permissions');
        foreach ($permissions as $permission) {
            $authorization->hasRole($permission);
        }
    }

    public function badWrite($related): void
    {
        // ruleid: php.appwrite.related-permissions-helper
        $related->setAttribute('$permissions', ['read("any")']);
    }

    public function badArray(array $nested): void
    {
        // ruleid: php.appwrite.related-permissions-helper
        $permissions = $nested['$permissions'] ?? null;
        $this->writeRelated($permissions);
    }

    public function badRelatedDoc($relatedDoc): void
    {
        // ruleid: php.appwrite.related-permissions-helper
        $permissions = $relatedDoc->getAttribute('$permissions');
        $this->writeRelated($permissions);
    }

    public function good($relation, $current, $authorization): void
    {
        // ok: php.appwrite.related-permissions-helper
        $this->validateRelatedPermissions($relation->getAttribute('$permissions'), $current, $authorization);
    }

    public function parentPermissions(array $data, $document, $collection): void
    {
        // ok: php.appwrite.related-permissions-helper
        $data['$permissions'] = [];
        // ok: php.appwrite.related-permissions-helper
        $document->setAttribute('$permissions', []);
        // ok: php.appwrite.related-permissions-helper
        $collection->setAttribute('$permissions', []);
    }
}
