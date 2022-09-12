<?php

namespace Appwrite\Utopia\Response\Filters;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Filter;
use Utopia\Database\Database;
use Utopia\Database\Permission;
use Utopia\Database\Role;

class V15 extends Filter
{
    // Convert 0.16 Data format to 0.15 format
    public function parse(array $content, string $model): array
    {
        $parsedResponse = $content;

        switch ($model) {
            case Response::MODEL_ACCOUNT:
            case Response::MODEL_USER:
                $parsedResponse = $this->parseUser($parsedResponse);
                break;
            case Response::MODEL_USER_LIST:
                $listKey = 'users';
                $parsedResponse[$listKey] = array_map(fn ($content) => $this->parseUser($content), $parsedResponse[$listKey]);
                break;
            case Response::MODEL_METRIC:
                $parsedResponse = $this->parseMetric($parsedResponse);
                break;
            case Response::MODEL_BUILD:
                $parsedResponse = $this->parseBuild($parsedResponse);
                break;
            case Response::MODEL_BUILD_LIST:
                $listKey = 'builds';
                $parsedResponse[$listKey] = array_map(fn ($content) => $this->parseBuild($content), $parsedResponse[$listKey]);
                break;
            case Response::MODEL_BUCKET:
                $parsedResponse = $this->parseBucket($parsedResponse);
                break;
            case Response::MODEL_BUCKET_LIST:
                $listKey = 'buckets';
                $parsedResponse[$listKey] = array_map(fn ($content) => $this->parseBucket($content), $parsedResponse[$listKey]);
                break;
            case Response::MODEL_COLLECTION:
                $parsedResponse = $this->parseCollection($parsedResponse);
                break;
            case Response::MODEL_COLLECTION_LIST:
                $listKey = 'collections';
                $parsedResponse[$listKey] = array_map(fn ($content) => $this->parseCollection($content), $parsedResponse[$listKey]);
                break;
            case Response::MODEL_DATABASE:
            case Response::MODEL_DEPLOYMENT:
            case Response::MODEL_DOMAIN:
            case Response::MODEL_PLATFORM:
            case Response::MODEL_PROJECT:
            case Response::MODEL_TEAM:
            case Response::MODEL_WEBHOOK:
                $parsedResponse = $this->parseCreatedAtUpdatedAt($parsedResponse);
                break;
            case Response::MODEL_DATABASE_LIST:
            case Response::MODEL_DEPLOYMENT_LIST:
            case Response::MODEL_DOMAIN_LIST:
            case Response::MODEL_PLATFORM_LIST:
            case Response::MODEL_PROJECT_LIST:
            case Response::MODEL_TEAM_LIST:
            case Response::MODEL_WEBHOOK_LIST:
                $listKey = '';
                switch ($model) {
                    case Response::MODEL_DATABASE_LIST:
                        $listKey = 'databases';
                        break;
                    case Response::MODEL_DEPLOYMENT_LIST:
                        $listKey = 'deployments';
                        break;
                    case Response::MODEL_DOMAIN_LIST:
                        $listKey = 'domains';
                        break;
                    case Response::MODEL_PLATFORM_LIST:
                        $listKey = 'platforms';
                        break;
                    case Response::MODEL_PROJECT_LIST:
                        $listKey = 'projects';
                        break;
                    case Response::MODEL_TEAM_LIST:
                        $listKey = 'teams';
                        break;
                    case Response::MODEL_WEBHOOK_LIST:
                        $listKey = 'webhooks';
                        break;
                }
                $parsedResponse[$listKey] = array_map(fn ($content) => $this->parseCreatedAtUpdatedAt($content), $parsedResponse[$listKey]);
                break;
            case Response::MODEL_DOCUMENT:
            case Response::MODEL_FILE:
                $parsedResponse = $this->parsePermissionsCreatedAtUpdatedAt($parsedResponse);
                break;
            case Response::MODEL_DOCUMENT_LIST:
            case Response::MODEL_FILE_LIST:
                $listKey = '';
                switch ($model) {
                    case Response::MODEL_DOCUMENT_LIST:
                        $listKey = 'documents';
                        break;
                    case Response::MODEL_FILE_LIST:
                        $listKey = 'files';
                        break;
                }
                $parsedResponse[$listKey] = array_map(fn ($content) => $this->parsePermissionsCreatedAtUpdatedAt($content), $parsedResponse[$listKey]);
                break;
            case Response::MODEL_EXECUTION:
                $parsedResponse = $this->parseExecution($parsedResponse);
                break;
            case Response::MODEL_EXECUTION_LIST:
                $listKey = 'executions';
                $parsedResponse[$listKey] = array_map(fn ($content) => $this->parseExecution($content), $parsedResponse[$listKey]);
                break;
            case Response::MODEL_FUNCTION:
                $parsedResponse = $this->parseFunction($parsedResponse);
                break;
            case Response::MODEL_FUNCTION_LIST:
                $listKey = 'functions';
                $parsedResponse[$listKey] = array_map(fn ($content) => $this->parseFunction($content), $parsedResponse[$listKey]);
                break;
            case Response::MODEL_KEY:
                $parsedResponse = $this->parseKey($parsedResponse);
                break;
            case Response::MODEL_KEY_LIST:
                $listKey = 'keys';
                $parsedResponse[$listKey] = array_map(fn ($content) => $this->parseKey($content), $parsedResponse[$listKey]);
                break;
            case Response::MODEL_LOG:
                $parsedResponse = $this->parseLog($parsedResponse);
                break;
            case Response::MODEL_LOG_LIST:
                $listKey = 'logs';
                $parsedResponse[$listKey] = array_map(fn ($content) => $this->parseLog($content), $parsedResponse[$listKey]);
                break;
            case Response::MODEL_MEMBERSHIP:
                $parsedResponse = $this->parseMembership($parsedResponse);
                break;
            case Response::MODEL_MEMBERSHIP_LIST:
                $listKey = 'memberships';
                $parsedResponse[$listKey] = array_map(fn ($content) => $this->parseMembership($content), $parsedResponse[$listKey]);
                break;
            case Response::MODEL_SESSION:
                $parsedResponse = $this->parseSession($parsedResponse);
                break;
            case Response::MODEL_SESSION_LIST:
                $listKey = 'sessions';
                $parsedResponse[$listKey] = array_map(fn ($content) => $this->parseSession($content), $parsedResponse[$listKey]);
                break;
            case Response::MODEL_TOKEN:
                $parsedResponse = $this->parseDatetimeAttributes($parsedResponse, ['$createdAt', 'expire']);
                break;
            case Response::MODEL_USAGE_DATABASES:
                $parsedResponse = $this->parseUsageDatabases($parsedResponse);
                break;
            case Response::MODEL_USAGE_DATABASE:
                $parsedResponse = $this->parseUsageDatabase($parsedResponse);
                break;
            case Response::MODEL_USAGE_COLLECTION:
                $parsedResponse = $this->parseUsageCollection($parsedResponse);
                break;
            case Response::MODEL_USAGE_USERS:
                $parsedResponse = $this->parseUsageUsers($parsedResponse);
                break;
            case Response::MODEL_USAGE_BUCKETS:
                $parsedResponse = $this->parseUsageBuckets($parsedResponse);
                break;
            case Response::MODEL_USAGE_FUNCTIONS:
                $parsedResponse = $this->parseUsageFuncs($parsedResponse);
                break;
            case Response::MODEL_USAGE_PROJECT:
                $parsedResponse = $this->parseUsageProject($parsedResponse);
                break;
            case Response::MODEL_USAGE_STORAGE:
                $parsedResponse = $this->parseUsageStorage($parsedResponse);
                break;
        }

        return $parsedResponse;
    }

    protected function parseBuild(array $content)
    {
        $content = $this->parseDatetimeAttributes($content, ['startTime', 'endTime']);

        return $content;
    }

    protected function parseBucket(array $content)
    {
        if (isset($content['fileSecurity'])) {
            if ($content['fileSecurity']) {
                $content['permission'] = 'file';
            } else {
                $content['permission'] = 'bucket';
            }
        }

        unset($content['fileSecurity']);
        unset($content['compression']);

        $content = $this->parsePermissions($content);
        $content = $this->parseDatetimeAttributes($content, ['$createdAt', '$updatedAt']);

        return $content;
    }

    protected function parseDatetimeAttributes(array $content, array $attributes): array
    {
        foreach ($attributes as $attribute) {
            if (isset($content[$attribute])) {
                $content[$attribute] = strtotime($content[$attribute]);
            }
        }
        return $content;
    }

    protected function parseUser(array $content): array
    {
        unset($content['password']);
        unset($content['hash']);
        unset($content['hashOptions']);

        $content = $this->parseDatetimeAttributes($content, ['registration', 'passwordUpdate', '$createdAt', '$updatedAt']);
        return $content;
    }

    protected function parseMetric(array $content)
    {
        $content = $this->parseDatetimeAttributes($content, ['date']);
        return $content;
    }

    protected function parsePermissions(array $content)
    {
        if (!isset($content['$permissions'])) {
            return $content;
        }

        $read = [];
        $write = [];

        // downgrade the permissions
        foreach ($content['$permissions'] as $permission) {
            $permission = Permission::parse($permission);
            $permission_value = $permission->getRole();
            if ($permission->getIdentifier()) {
                $permission_value .= ':' . $permission->getIdentifier();
            }
            if ($permission->getDimension()) {
                $permission_value .= '/' . $permission->getDimension();
            }

            // Old type permissions meant that 'write' is equivalent to 'create', 'update' and 'delete'
            switch ($permission->getPermission()) {
                case Database::PERMISSION_UPDATE:
                case Database::PERMISSION_DELETE:
                case Database::PERMISSION_WRITE:
                case Database::PERMISSION_CREATE:
                    $write[$this->parseRole($permission_value)] = true;
                    break;
                case Database::PERMISSION_READ:
                    $read[$this->parseRole($permission_value)] = true;
                    break;
            }
        }

        $content['$read'] = array_keys($read);
        $content['$write'] = array_keys($write);

        unset($content['$permissions']);

        return $content;
    }

    protected function parseRole(string $role)
    {
        switch ($role) {
            case Role::any()->toString():
                return 'role:all';
            case Role::users()->toString():
                return 'role:member';
            case Role::guests()->toString():
                return 'role:guest';
            default:
                return $role;
        }

        return $role;
    }

    protected function parseCollection(array $content)
    {
        if (isset($content['documentSecurity'])) {
            if ($content['documentSecurity']) {
                $content['permission'] = 'document';
            } else {
                $content['permission'] = 'collection';
            }
        }

        unset($content['documentSecurity']);
        $content = $this->parsePermissions($content);
        $content = $this->parseDatetimeAttributes($content, ['$createdAt', '$updatedAt']);
        return $content;
    }

    protected function parsePermissionsCreatedAtUpdatedAt(array $content)
    {
        $content = $this->parsePermissions($content);
        $content = $this->parseDatetimeAttributes($content, ['$createdAt', '$updatedAt']);
        return $content;
    }

    private function parseExecution($content)
    {
        unset($content['stdout']);
        $content = $this->parsePermissions($content);
        $content = $this->parseDatetimeAttributes($content, ['$createdAt', '$updatedAt', 'startTime', 'endTime']);
        return $content;
    }

    private function parseCreatedAtUpdatedAt($content)
    {
        $content = $this->parseDatetimeAttributes($content, ['$createdAt', '$updatedAt']);
        return $content;
    }

    private function parseFunction($content)
    {
        if (isset($content['execute'])) {
            foreach ($content['execute'] as $i => $role) {
                $content['execute'][$i] = $this->parseRole($role);
            }
        }

        if (isset($content['vars'])) {
            $vars = [];
            foreach ($content['vars'] as $i => $var) {
                $vars[$var['key']] = $var['value'];
            }
            $content['vars'] = $vars;
        }

        $content = $this->parseDatetimeAttributes($content, ['$createdAt', '$updatedAt', 'scheduleNext', 'schedulePrevious']);
        return $content;
    }

    private function parseKey($content)
    {
        $content = $this->parseDatetimeAttributes($content, ['$createdAt', '$updatedAt', 'expire']);
        return $content;
    }

    private function parseLog($content)
    {
        $content = $this->parseDatetimeAttributes($content, ['$createdAt', '$updatedAt', 'time']);
        return $content;
    }

    private function parseMembership($content)
    {
        $content = $this->parseDatetimeAttributes($content, ['$createdAt', '$updatedAt', 'invited', 'joined']);
        return $content;
    }

    private function parseSession($content)
    {
        $content = $this->parseDatetimeAttributes($content, ['$createdAt', 'expire', 'providerAccessTokenExpiry']);
        return $content;
    }

    private function parseUsage($content, $keys)
    {
        foreach ($keys as $key) {
            $data = [];
            foreach ($content[$key] as $metric) {
                $data[] = $this->parseMetric($metric);
            }
            $content[$key] = $data;
        }

        return $content;
    }

    private function parseUsageDatabases($content)
    {
        $keys = [
            'databasesCount',
            'documentsCount',
            'collectionsCount',
            'databasesCreate',
            'databasesRead',
            'databasesUpdate',
            'databasesDelete',
            'documentsCreate',
            'documentsRead',
            'documentsUpdate',
            'documentsDelete',
            'collectionsCreate',
            'collectionsRead',
            'collectionsUpdate',
            'collectionsDelete',
        ];

        $content = $this->parseUsage($content, $keys);

        return $content;
    }

    private function parseUsageDatabase($content)
    {
        $keys = [
            'documentsCount',
            'collectionsCount',
            'documentsCreate',
            'documentsRead',
            'documentsUpdate',
            'documentsDelete',
            'collectionsCreate',
            'collectionsRead',
            'collectionsUpdate',
            'collectionsDelete',
        ];

        $content = $this->parseUsage($content, $keys);

        return $content;
    }

    private function parseUsageCollection($content)
    {
        $keys = [
            'documentsCount',
            'documentsCreate',
            'documentsRead',
            'documentsUpdate',
            'documentsDelete',
        ];

        $content = $this->parseUsage($content, $keys);

        return $content;
    }

    private function parseUsageUsers($content)
    {
        $keys = [
            'usersCount',
            'usersCreate',
            'usersRead',
            'usersUpdate',
            'usersDelete',
            'sessionsCreate',
            'sessionsProviderCreate',
            'sessionsDelete',
        ];

        $content = $this->parseUsage($content, $keys);

        return $content;
    }

    private function parseUsageBuckets($content)
    {
        $keys = [
            'filesCount',
            'filesStorage',
            'filesCreate',
            'filesRead',
            'filesUpdate',
            'filesDelete',
        ];

        $content = $this->parseUsage($content, $keys);

        return $content;
    }

    private function parseUsageFuncs($content)
    {
        $mapping = [
            'executionsTotal' => 'functionsExecutions',
            'executionsFailure' => 'functionsFailures',
            'executionsTime' => 'functionsCompute',
        ];

        foreach ($mapping as $new => $old) {
            if (isset($content[$new])) {
                $data = [];
                foreach ($content[$new] as $metric) {
                    $data[] = $this->parseMetric($metric);
                }
                $content[$old] = $data;
                unset($content[$new]);
            }
        }

        unset($content['functionExecutions']);
        unset($content['functionFailure']);
        unset($content['executionsTime']);
        unset($content['buildsTotal']);
        unset($content['executionsSuccess']);
        unset($content['buildsFailure']);
        unset($content['buildsSuccess']);
        unset($content['buildsTime']);

        return $content;
    }

    private function parseUsageProject($content)
    {
        $content['functions'] = $content['executions'];
        unset($content['executions']);

        $keys = [
            'collections',
            'documents',
            'functions',
            'network',
            'requests',
            'storage',
            'users',
        ];

        $content = $this->parseUsage($content, $keys);

        return $content;
    }

    private function parseUsageStorage($content)
    {
        $content['filesStorage'] = $content['storage'];
        unset($content['storage']);

        $keys = [
            'bucketsCount',
            'bucketsCreate',
            'bucketsDelete',
            'bucketsRead',
            'bucketsUpdate',
            'filesCount',
            'filesCreate',
            'filesDelete',
            'filesRead',
            'filesStorage',
            'filesUpdate',
        ];

        $content = $this->parseUsage($content, $keys);

        return $content;
    }
}
