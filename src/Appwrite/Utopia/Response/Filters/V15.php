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
            case Response::MODEL_METRIC:
                $parsedResponse = $this->parseMetric($parsedResponse);
                break;
            case Response::MODEL_BUILD:
                $parsedResponse = $this->parseDatetimeAttributes($parsedResponse, ['startTime', 'endTime']);
                break;
            case Response::MODEL_BUCKET:
                $parsedResponse = $this->parseBucket($parsedResponse);
                break;
            case Response::MODEL_COLLECTION:
                $parsedResponse = $this->parseCollection($parsedResponse);
                break;
            case Response::MODEL_DEPLOYMENT:
            case Response::MODEL_DOCUMENT:
                $parsedResponse = $this->parseDatetimeAttributes($parsedResponse, ['$createdAt', '$updatedAt']);
                break;
            case Response::MODEL_EXECUTION:
                $parsedResponse = $this->parseExecution($parsedResponse);
                break;
            case Response::MODEL_PLATFORM:
            case Response::MODEL_PROJECT:
            case Response::MODEL_TEAM:
            case Response::MODEL_FILE:
            case Response::MODEL_WEBHOOK:
            case Response::MODEL_DOMAIN:
            case Response::MODEL_DATABASE:
                $parsedResponse = $this->parseDatetimeAttributes($parsedResponse, ['$createdAt', '$updatedAt']);
                break;
            case Response::MODEL_FUNCTION:
                $parsedResponse = $this->parseFunction($parsedResponse);
                break;
            case Response::MODEL_KEY:
                $parsedResponse = $this->parseDatetimeAttributes($parsedResponse, ['$createdAt', '$updatedAt', 'expire']);
                break;
            case Response::MODEL_LOG:
                $parsedResponse = $this->parseDatetimeAttributes($parsedResponse, ['$createdAt', '$updatedAt', 'time']);
                break;
            case Response::MODEL_MEMBERSHIP:
                $parsedResponse = $this->parseDatetimeAttributes($parsedResponse, ['$createdAt', '$updatedAt', 'invited', 'joined']);
                break;
            case Response::MODEL_SESSION:
                $parsedResponse = $this->parseDatetimeAttributes($parsedResponse, ['$createdAt', 'expire', 'providerAccessTokenExpiry']);
                break;
            case Response::MODEL_TOKEN:
                $parsedResponse = $this->parseDatetimeAttributes($parsedResponse, ['$createdAt', 'expire']);
                break;
            case Response::MODEL_USAGE_FUNCTIONS:
                $parsedResponse = $this->parseModelUsageFunc($parsedResponse);
                break;
            case Response::MODEL_USAGE_PROJECT:
                $parsedResponse = $this->parseUsageProject($parsedResponse);
                break;
            case Response::MODEL_USAGE_STORAGE:
                $parsedResponse = $this->parseUsageStorage($parsedResponse);
                break;
        }

        // Downgrade Permissions for all models
        $parsedResponse = $this->parsePermissions($parsedResponse);

        return $parsedResponse;
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
        $content = $this->parseDatetimeAttributes($content, ['$createdAt', '$updatedAt']);
        return $content;
    }

    private function parseExecution($content)
    {
        unset($content['stdout']);
        $content = $this->parseDatetimeAttributes($content, ['$createdAt', '$updatedAt', 'startTime', 'endTime']);
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

    private function parseModelUsageFunc($content)
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

        $usage = [
            'collections',
            'documents',
            'functions',
            'network',
            'requests',
            'storage',
            'users',
        ];

        foreach ($usage as $name) {
            $data = [];
            foreach ($content[$name] as $metric) {
                $data[] = $this->parseMetric($metric);
            }
            $content[$name] = $data;
        }

        return $content;
    }

    private function parseUsageStorage($content)
    {
        $content['filesStorage'] = $content['storage'];
        unset($content['storage']);

        $usage = [
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

        foreach ($usage as $name) {
            $data = [];
            foreach ($content[$name] as $metric) {
                $data[] = $this->parseMetric($metric);
            }
            $content[$name] = $data;
        }

        return $content;
    }
}
