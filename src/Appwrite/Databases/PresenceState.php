<?php

namespace Appwrite\Databases;

use Appwrite\Extend\Exception;
use Appwrite\Utopia\Database\Documents\User;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Conflict as ConflictException;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Exception\NotFound as NotFoundException;
use Utopia\Database\Exception\Structure as StructureException;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;

class PresenceState
{
    public const LIST_CACHE_FIELD_PRESENCES = 'presences';
    public const LIST_CACHE_FIELD_TOTAL = 'total';
    public const COLLECTION_ID = 'presenceLogs';
    public function setPermissions(Document $document, ?array $permissions, User $user, Authorization $authorization): Document
    {
        $isAPIKey = $user->isApp($authorization->getRoles());
        $isPrivilegedUser = $user->isPrivileged($authorization->getRoles());

        $allowedPermissions = [
            Database::PERMISSION_READ,
            Database::PERMISSION_UPDATE,
            Database::PERMISSION_DELETE,
            Database::PERMISSION_WRITE,
        ];

        $permissions = Permission::aggregate($permissions, $allowedPermissions);

        if (\is_null($permissions)) {
            $permissions = [];
            if (!empty($user->getId()) && !$isPrivilegedUser) {
                foreach ($allowedPermissions as $permission) {
                    $permissions[] = (new Permission($permission, 'user', $user->getId()))->toString();
                }
            }
        }

        if (!$isAPIKey && !$isPrivilegedUser) {
            $this->assertPermissionsAgainstAuthorization($permissions, $authorization);
        }

        sort($permissions, SORT_STRING);
        $document->setAttribute('$permissions', $permissions);
        $document->setAttribute('perms_md5', \md5(\json_encode($permissions)));

        return $document;
    }

    public function upsertForUser(
        Database $dbForProject,
        Document $presenceDocument,
        string $presenceId,
        mixed $userInternalId,
        ?callable $onPresenceCreated = null
    ): Document {
        if ($presenceId !== 'unique()') {
            $presenceDocument->setAttribute('$id', $presenceId);
        }

        $presenceCreated = false;

        try {
            if ($dbForProject->getAdapter()->getSupportForUpsertOnUniqueIndex()) {
                // in v2 use permsmd5 in the queries as well to find the doc
                $existingPresence = $dbForProject->findOne(self::COLLECTION_ID, [Query::equal('userInternalId', [$userInternalId])]);
                if ($existingPresence->isEmpty()) {
                    $presenceCreated = true;
                } else {
                    $presenceDocument->setAttribute('$id', $existingPresence->getId());
                }
                $presence = $dbForProject->upsertDocument(self::COLLECTION_ID, $presenceDocument);
            } else {
                $presence = $this->transactionalUpsertForUser(
                    $dbForProject,
                    $presenceDocument,
                    $userInternalId,
                    $presenceCreated
                );
            }

            if ($presenceCreated && $onPresenceCreated !== null) {
                call_user_func($onPresenceCreated);
            }

            return $presence;
        } catch (DuplicateException $e) {
            throw new Exception(Exception::DOCUMENT_ALREADY_EXISTS, params: [$presenceId], previous: $e);
        } catch (NotFoundException $e) {
            throw new Exception(Exception::DOCUMENT_NOT_FOUND, params: [$presenceId], previous: $e);
        } catch (StructureException $e) {
            throw new Exception(Exception::DOCUMENT_INVALID_STRUCTURE, $e->getMessage(), previous: $e);
        } catch (ConflictException $e) {
            throw new Exception(Exception::DOCUMENT_UPDATE_CONFLICT, $e->getMessage(), previous: $e);
        }
    }

    private function transactionalUpsertForUser(
        Database $dbForProject,
        Document $presenceDocument,
        mixed $userInternalId,
        ?bool &$presenceCreated = null
    ): Document {
        return $dbForProject->withTransaction(function () use ($dbForProject, $presenceDocument, $userInternalId, &$presenceCreated) {
            $existingPresence = $dbForProject->findOne(self::COLLECTION_ID, [Query::equal('userInternalId', [$userInternalId])]);

            if ($existingPresence->isEmpty()) {
                $presenceCreated = true;
                return $dbForProject->createDocument(self::COLLECTION_ID, $presenceDocument);
            }

            $currentPresence = $dbForProject->getDocument(self::COLLECTION_ID, $existingPresence->getId(), forUpdate: true);

            if ($currentPresence->isEmpty()) {
                throw new Exception(Exception::DOCUMENT_NOT_FOUND, params: [$existingPresence->getId()]);
            }

            $presenceDocument->setAttribute('$id', $currentPresence->getId());

            return $dbForProject->updateDocument(self::COLLECTION_ID, $currentPresence->getId(), $presenceDocument);
        });
    }

    public function setOwnerPermissions(Document $document, string $userId): Document
    {
        $allowedPermissions = [
            Database::PERMISSION_READ,
            Database::PERMISSION_UPDATE,
            Database::PERMISSION_DELETE,
            Database::PERMISSION_WRITE,
        ];

        $ownerPermissions = [];
        foreach ($allowedPermissions as $permission) {
            $ownerPermissions[] = (new Permission($permission, 'user', $userId))->toString();
        }

        sort($ownerPermissions, SORT_STRING);
        $document->setAttribute('$permissions', $ownerPermissions);
        $document->setAttribute('perms_md5', \md5(\json_encode($ownerPermissions)));

        return $document;
    }

    private function assertPermissionsAgainstAuthorization(array $permissions, Authorization $authorization): void
    {
        foreach (Database::PERMISSIONS as $type) {
            foreach ($permissions as $permission) {
                $permission = Permission::parse($permission);
                if ($permission->getPermission() != $type) {
                    continue;
                }

                $role = (new Role(
                    $permission->getRole(),
                    $permission->getIdentifier(),
                    $permission->getDimension()
                ))->toString();

                if (!$authorization->hasRole($role)) {
                    throw new Exception(Exception::USER_UNAUTHORIZED, 'Permissions must be one of: (' . \implode(', ', $authorization->getRoles()) . ')');
                }
            }
        }
    }

    private function getListCacheKey(Database $dbForProject): string
    {
        return \sprintf(
            '%s-cache:%s:%s:%s:collection:%s',
            $dbForProject->getCacheName(),
            $dbForProject->getAdapter()->getHostname(),
            $dbForProject->getNamespace(),
            $dbForProject->getTenant(),
            self::COLLECTION_ID
        );
    }

    private function getListCacheField(array $roles, array $queries, string $type): string
    {
        $serialized = \array_map(
            static fn ($query) => $query instanceof Query ? $query->toArray() : $query,
            $queries,
        );

        return \sprintf(
            '%s:%s:%s',
            \md5(\json_encode($roles)),
            \md5(\json_encode($serialized)),
            $type,
        );
    }

    public function loadListCacheField(
        Database $dbForProject,
        array $roles,
        array $queries,
        string $type,
        int $ttl
    ): mixed {
        $cacheField = $this->getListCacheField($roles, $queries, $type);

        try {
            return $dbForProject->getCache()->load($this->getListCacheKey($dbForProject), $ttl, $cacheField);
        } catch (\Throwable) {
            return null;
        }
    }

    public function saveListCacheField(
        Database $dbForProject,
        array $roles,
        array $queries,
        string $type,
        mixed $value
    ): void {
        $cacheField = $this->getListCacheField($roles, $queries, $type);

        try {
            $dbForProject->getCache()->save($this->getListCacheKey($dbForProject), $value, $cacheField);
        } catch (\Throwable) {
        }
    }

    public function purgeListCache(Database $dbForProject): bool
    {
        return $dbForProject->getCache()->purge($this->getListCacheKey($dbForProject));
    }
}
