<?php

namespace Appwrite\Utopia\Response\Filters;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Filter;
use Utopia\Database\Permission;

class V15 extends Filter
{
    // Convert 0.16 Data format to 0.15 format
    public function parse(array $content, string $model): array
    {
        $parsedResponse = $content;

        switch ($model) {
            case Response::MODEL_USER:
                $parsedResponse = $this->handleDatetimeAttributes($content, ['$createdAt', '$updatedAt', 'registration', 'passwordUpdate']);
                $parsedResponse = $this->handleUser($parsedResponse);
                break;
            case Response::MODEL_METRIC:
                $parsedResponse = $this->handleMetricAttributes($content);
                break;
            case Response::MODEL_BUILD:
                $parsedResponse = $this->handleDatetimeAttributes($content, ['startTime', 'endTime']);
                break;
            case Response::MODEL_BUCKET:
                $parsedResponse = $this->handleBucketAttributes($content);
                break;
            case Response::MODEL_COLLECTION:
                $parsedResponse = $this->handleCollectionAttributes($content);
                break;
            case Response::MODEL_DEPLOYMENT:
            case Response::MODEL_DOCUMENT:
            case Response::MODEL_EXECUTION:
                $parsedResponse = $this->handleExecutionAttributes($content);
                break;
            case Response::MODEL_PLATFORM:
            case Response::MODEL_PROJECT:
            case Response::MODEL_TEAM:
            case Response::MODEL_FILE:
            case Response::MODEL_WEBHOOK:
            case Response::MODEL_DOMAIN:
            case Response::MODEL_DATABASE:
                $parsedResponse = $this->handleDatetimeAttributes($content, ['$createdAt', '$updatedAt']);
                break;
            case Response::MODEL_FUNCTION:
                $parsedResponse = $this->handleFunctionAttribtues($content);
                break;
            case Response::MODEL_KEY:
                $parsedResponse = $this->handleDatetimeAttributes($content, ['$createdAt', '$updatedAt', 'expire']);
                break;
            case Response::MODEL_LOG:
                $parsedResponse = $this->handleDatetimeAttributes($content, ['$createdAt', '$updatedAt', 'time']);
                break;
            case Response::MODEL_MEMBERSHIP:
                $parsedResponse = $this->handleDatetimeAttributes($content, ['$createdAt', '$updatedAt', 'invited', 'joined']);
                break;
            case Response::MODEL_SESSION:
                $parsedResponse = $this->handleDatetimeAttributes($content, ['$createdAt', 'expire', 'providerAccessTokenExpiry']);
                break;
            case Response::MODEL_TOKEN:
                $parsedResponse = $this->handleDatetimeAttributes($content, ['$createdAt', 'expire']);
                break;
            case Response::MODEL_USAGE_FUNCTIONS:
                $parsedResponse = $this->handleModelUsageFuncAttributes($content);
        }

        // Downgrade Permissions for all models
        $parsedResponse = $this->handleDowngradePermissions($parsedResponse);

        return $parsedResponse;
    }

    protected function handleBucketAttributes(array $content)
    {
        if ($content['fileSecurity']) {
            $content['permssion'] = 'file';
        } else {
            $content['permssion'] = 'bucket';
        }

        unset($content['fileSecurity']);
        unset($content['compression']);

        $content = $this->handleDatetimeAttributes($content, ['$createdAt', '$updatedAt']);
    }

    protected function handleDatetimeAttributes(array $content, array $attributes): array
    {
        foreach ($attributes as $attribute) {
            if (isset($content[$attribute])) {
                $content[$attribute] = strtotime($content[$attribute]);
            }
        }
        return $content;
    }

    protected function handleUser(array $content): array
    {
        unset($content['password']);
        unset($content['hash']);
        unset($content['hashOptions']);

        $content = $this->handleDatetimeAttributes($content, ['registration', 'passwordUpdate', '$createdAt', '$updatedAt']);
        return $content;
    }

    protected function handleMetricAttributes(array $content)
    {
        $content['timestamp'] = $content['date'];
        unset($content['date']);
    }

    protected function handleDowngradePermissions(array $content)
    {
        if (!isset($content['$permissions'])) {
            return $content;
        }
        $content = array_merge($content, $this->downgradePermissions($content['permissions']));
        unset($content['permissions']);
        return $content;
    }

    protected function downgradePermissionSelector(string $permSelector)
    {
        switch ($permSelector) {
            case 'any':
                return 'role:all';
            case 'users':
                return 'role:user';
            case 'guests':
                return 'role:guest';
        }

        return $permSelector;
    }

    protected function downgradePermissions(array $model)
    {
        if (!isset($model['$permissions'])) {
            return $model;
        }

        $permissions = $model['$permissions'];

        $result = [
            '$read' => [],
            '$write' => []
        ];

        // downgrade the permissions
        foreach ($permissions as $permission) {
            $permission = Permission::parse($permission);
            // permission = "read('any')" = ["$read" => "role:all"]

            // Old type permissions meant that 'write' is equivalent to 'create', 'update' and 'delete'

            switch ($permission->getPermission()) {
                case 'update':
                case 'delete':
                case 'write':
                case 'create':
                    if (!in_array($this->downgradePermissionSelector($permission_value), $result['write'])) {
                        $result['$write'][] = $this->downgradePermissionSelector($permission_value);
                    }
                    break;
                case 'read':
                    if (!in_array($this->downgradePermissionSelector($permission_value), $result['read'])) {
                        $result['$read'][] = $this->downgradePermissionSelector($permission_value);
                    }
                    break;
            }
        }

        unset($model['$permissions']);
        return array_merge($model, $result);
    }

    protected function handleCollectionAttributes(array $content)
    {
        $content['permission'] = $content['documentSecurity'];

        unset($content['documentSecurity']);
        $content = $this->handleDatetimeAttributes($content, ['$createdAt', '$updatedAt']);
        return $content;
    }

    private function handleExecutionAttributes($content)
    {
        unset($content['stdout']);

        $content = $this->handleDatetimeAttributes($content, ['$createdAt', '$updatedAt']);
        return $content;
    }

    private function handleFunctionAttribtues($content)
    {
        $content['execute'] = array_map($this->downgradePermissionSelector, $content['execute']);

        $content = $this->handleDatetimeAttributes($content, ['$createdAt', '$updatedAt', 'scheduleNext', 'schedulePrevious']);
        return $content;
    }

    private function handleModelUsageFuncAttributes($content)
    {
        $content['functiosnExecutions'] = $content['executionsTotal'];
        $content['functionsFailures'] = $content['executionsFailure'];
        $content['functionsCompute'] = $content['executionsTime'];
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

    private function handleUsageProjectAttributes($content)
    {
        $content['functions'] = $content['executions'];
        unset($content['executions']);

        return $content;
    }

    private function handleUsageStorageAttribtues($content)
    {
        $content['filesStorage'] = $content['storage'];
        $content['tagsStorage'] = [];
        unset($content['storage']);
    }
}
