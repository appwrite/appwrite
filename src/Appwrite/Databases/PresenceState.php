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
use Utopia\System\System;

class PresenceState
{
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
        string $userId,
        ?callable $onPresenceCreated = null
    ): Document {
        if ($presenceId !== 'unique()') {
            $presenceDocument->setAttribute('$id', $presenceId);
        }

        $presenceCreated = false;

        try {
            if ($this->getSupportForUniqueIndexBasedUpsert()) {
                $presenceCreated = $dbForProject->findOne('presenceLogs', [Query::equal('userId', [$userId])])->isEmpty();
                $presence = $dbForProject->upsertDocument('presenceLogs', $presenceDocument);
            } else {
                $presence = $this->transactionalUpsertForUser(
                    $dbForProject,
                    $presenceDocument,
                    $presenceId,
                    $userId,
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
        string $presenceId,
        string $userId,
        ?bool &$presenceCreated = null
    ): Document {
        return $dbForProject->withTransaction(function () use ($dbForProject, $presenceDocument, $presenceId, $userId, &$presenceCreated) {
            $existingPresence = $dbForProject->findOne('presenceLogs', [Query::equal('userId', [$userId])]);

            if ($existingPresence->isEmpty()) {
                $presenceCreated = true;
                return $dbForProject->createDocument('presenceLogs', $presenceDocument);
            }

            // Lock current state to avoid races while resolving upsert by userId.
            $currentPresence = $dbForProject->getDocument('presenceLogs', $existingPresence->getId(), forUpdate: true);

            if ($currentPresence->isEmpty()) {
                throw new Exception(Exception::DOCUMENT_NOT_FOUND, params: [$existingPresence->getId()]);
            }

            if ($presenceId !== 'unique()' && $currentPresence->getId() !== $presenceId) {
                $presenceDocument->setAttribute('$id', $presenceId);
                $dbForProject->deleteDocument('presenceLogs', $currentPresence->getId());
                return $dbForProject->createDocument('presenceLogs', $presenceDocument);
            }

            return $dbForProject->updateDocument('presenceLogs', $currentPresence->getId(), $presenceDocument);
        });
    }

    private function getSupportForUniqueIndexBasedUpsert(): bool
    {
        $adapter = \strtolower(System::getEnv('_APP_DB_ADAPTER', 'mariadb'));
        return !\in_array($adapter, ['mongodb', 'postgres', 'postgresql'], true);
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
}
