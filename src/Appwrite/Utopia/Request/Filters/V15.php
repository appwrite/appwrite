<?php

namespace Appwrite\Utopia\Request\Filters;

use Appwrite\Utopia\Request\Filter;
use Utopia\Database\Database;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;

class V15 extends Filter
{
    // Convert 0.15 params format to 0.16 format
    public function parse(array $content, string $model): array
    {
        switch ($model) {
            case 'account.listLogs':
            case 'databases.listLogs':
            case 'databases.listCollectionLogs':
            case 'databases.listDocumentLogs':
            case 'teams.listLogs':
            case 'users.listLogs':
                $content = $this->convertLimitAndOffset($content);
                break;
            case 'account.initials':
                unset($content['color']);
                break;
            case 'databases.list':
            case 'databases.listCollections':
            case 'functions.list':
            case 'functions.listDeployments':
            case 'projects.list':
            case 'storage.listBuckets':
            case 'storage.listFiles':
            case 'teams.list':
            case 'teams.listMemberships':
            case 'users.list':
                $content = $this->convertLimitAndOffset($content);
                $content = $this->convertCursor($content);
                $content = $this->convertOrderType($content);
                break;
            case 'databases.createCollection':
            case 'databases.updateCollection':
                $content = $this->convertCollectionPermission($content);
                $content = $this->convertReadWrite($content);
                break;
            case 'databases.createDocument':
            case 'databases.updateDocument':
            case 'storage.createFile':
            case 'storage.updateFile':
                $content = $this->convertReadWrite($content);
                break;
            case 'databases.listDocuments':
                $content = $this->convertFilters($content);
                $content = $this->convertLimitAndOffset($content);
                $content = $this->convertCursor($content);
                $content = $this->convertOrders($content);
                break;
            case 'functions.create':
            case 'functions.update':
                $content = $this->convertExecute($content);
                break;
            case 'functions.listExecutions':
                $content = $this->convertLimitAndOffset($content);
                $content = $this->convertCursor($content);
                break;
            case 'projects.createKey':
            case 'projects.updateKey':
                $content = $this->convertExpire($content);
                break;
            case 'storage.createBucket':
            case 'storage.updateBucket':
                $content = $this->convertBucketPermission($content);
                $content = $this->convertReadWrite($content);
                break;
        }

        return $content;
    }

    protected function convertLimitAndOffset($content)
    {
        if (isset($content['limit'])) {
            $content['queries'][] = 'limit('.$content['limit'].')';
        }

        if (isset($content['offset'])) {
            $content['queries'][] = 'offset('.$content['offset'].')';
        }

        unset($content['limit']);
        unset($content['offset']);

        return $content;
    }

    protected function convertCursor($content)
    {
        if (isset($content['cursor'])) {
            $cursorDirection = $content['cursorDirection'] ?? Database::CURSOR_AFTER;

            if ($cursorDirection === Database::CURSOR_BEFORE) {
                $content['queries'][] = 'cursorBefore("'.$content['cursor'].'")';
            } else {
                $content['queries'][] = 'cursorAfter("'.$content['cursor'].'")';
            }
        }

        unset($content['cursor']);
        unset($content['cursorDirection']);

        return $content;
    }

    protected function convertOrderType($content)
    {
        if (isset($content['orderType'])) {
            if ($content['orderType'] === Database::ORDER_DESC) {
                $content['queries'][] = 'orderDesc("")';
            } else {
                $content['queries'][] = 'orderAsc("")';
            }
        }
        unset($content['orderType']);

        return $content;
    }

    protected function convertOrders($content)
    {
        if (isset($content['orderTypes'])) {
            foreach ($content['orderTypes'] as $i => $type) {
                $attribute = $content['orderAttributes'][$i] ?? '';

                if ($type === Database::ORDER_DESC) {
                    $content['queries'][] = 'orderDesc("'.$attribute.'")';
                } else {
                    $content['queries'][] = 'orderAsc("'.$attribute.'")';
                }
            }
        }

        unset($content['orderAttributes']);
        unset($content['orderTypes']);

        return $content;
    }

    protected function convertCollectionPermission($content)
    {
        if (isset($content['permission'])) {
            $content['documentSecurity'] = $content['permission'] === 'document';
        }

        unset($content['permission']);

        return $content;
    }

    protected function convertReadWrite($content)
    {
        if (isset($content['read'])) {
            foreach ($content['read'] as $read) {
                if ($read === 'role:all') {
                    $content['permissions'][] = Permission::read(Role::any());
                } elseif ($read === 'role:guest') {
                    $content['permissions'][] = Permission::read(Role::guests());
                } elseif ($read === 'role:member') {
                    $content['permissions'][] = Permission::read(Role::users());
                } elseif (str_contains($read, ':')) {
                    $content['permissions'][] = Permission::read(Role::parse($read));
                }
            }
        }

        if (isset($content['write'])) {
            foreach ($content['write'] as $write) {
                if ($write === 'role:all' || $write === 'role:member') {
                    $content['permissions'][] = Permission::write(Role::users());
                } elseif ($write === 'role:guest') {
                    // don't add because, historically,
                    // role:guest for write did nothing
                } elseif (str_contains($write, ':')) {
                    $content['permissions'][] = Permission::write(Role::parse($write));
                }
            }
        }

        unset($content['read']);
        unset($content['write']);

        return $content;
    }

    protected function convertFilters($content)
    {
        if (! isset($content['queries'])) {
            return $content;
        }

        $operations = [
            'equal' => Query::TYPE_EQUAL,
            'notEqual' => Query::TYPE_NOTEQUAL,
            'lesser' => Query::TYPE_LESSER,
            'lesserEqual' => Query::TYPE_LESSEREQUAL,
            'greater' => Query::TYPE_GREATER,
            'greaterEqual' => Query::TYPE_GREATEREQUAL,
            'search' => Query::TYPE_SEARCH,
        ];
        foreach ($content['queries'] as $i => $query) {
            foreach ($operations as $oldOperation => $newOperation) {
                $middle = ".$oldOperation(";
                if (str_contains($query, $middle)) {
                    $parts = explode($middle, $query);
                    if (count($parts) > 1) {
                        $attribute = $parts[0];
                        $value = rtrim($parts[1], ')');
                        $content['queries'][$i] = $newOperation.'("'.$attribute.'", ['.$value.'])';
                    }
                }
            }
        }

        return $content;
    }

    protected function convertExecute($content)
    {
        if (! isset($content['execute'])) {
            return $content;
        }

        $execute = [];
        foreach ($content['execute'] as $role) {
            if ($role === 'role:all' || $role === 'role:member') {
                $execute[] = Role::users()->toString();
            } elseif ($role === 'role:guest') {
                // don't add because, historically,
                // role:guest for write did nothing
            } elseif (str_contains($role, ':')) {
                $execute[] = $role;
            }
        }
        $content['execute'] = $execute;

        return $content;
    }

    protected function convertExpire($content)
    {
        if (! isset($content['expire'])) {
            return $content;
        }

        $expire = (int) $content['expire'];

        if ($expire === 0) {
            $content['expire'] = null;
        } else {
            $content['expire'] = date(\DateTime::RFC3339_EXTENDED, $expire);
        }

        return $content;
    }

    protected function convertBucketPermission($content)
    {
        if (isset($content['permission'])) {
            $content['fileSecurity'] = $content['permission'] === 'file';
        }

        unset($content['permission']);

        return $content;
    }
}
