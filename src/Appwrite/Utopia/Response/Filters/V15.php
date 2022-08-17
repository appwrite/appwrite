<?php

namespace Appwrite\Utopia\Response\Filters;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Filter;

class V15 extends Filter
{
    // Convert 0.16 Data format to 0.15 format
    public function parse(array $content, string $model): array
    {
        $parsedResponse = $content;

        switch ($model) {
            case Response::MODEL_SESSION:
            case Response::MODEL_TOKEN:
            case Response::MODEL_SESSION_LIST:
            case Response::MODEL_DOCUMENT:
            case Response::MODEL_DOMAIN:
            case Response::MODEL_FUNCTION:
            case Response::MODEL_TEAM:
            case Response::MODEL_MEMBERSHIP:
            case Response::MODEL_PLATFORM:
            case Response::MODEL_PROJECT:
            case Response::MODEL_USER:
            case Response::MODEL_WEBHOOK:
            case Response::MODEL_DOCUMENT_LIST:
            case Response::MODEL_DOMAIN_LIST:
            case Response::MODEL_FUNCTION_LIST:
            case Response::MODEL_TEAM_LIST:
            case Response::MODEL_MEMBERSHIP_LIST:
            case Response::MODEL_PLATFORM_LIST:
            case Response::MODEL_PROJECT_LIST:
            case Response::MODEL_USER_LIST:
            case Response::MODEL_WEBHOOK_LIST:
            case Response::MODEL_TEAM:
            case Response::MODEL_EXECUTION:
            case Response::MODEL_FILE:
            case Response::MODEL_TEAM_LIST:
            case Response::MODEL_EXECUTION_LIST:
            case Response::MODEL_FILE_LIST:
            case Response::MODEL_FUNCTION:
            case Response::MODEL_DEPLOYMENT:
            case Response::MODEL_BUCKET:
            case Response::MODEL_FUNCTION_LIST:
            case Response::MODEL_DEPLOYMENT_LIST:
            case Response::MODEL_BUCKET_LIST:
            case Response::MODEL_METRIC:
                $parsedResponse = $this->handleMetricAttributes($content);
        }

        return $parsedResponse;
    }

    protected function handleMetricAttributes(array $content) 
    {
        $content['timestamp'] = $content['date'];
        unset($content['date']);
    }

    protected function downgradePermissionSelector(string $permSelector)
    {
        switch ($permSelector)
        {
            case 'any':
                return 'role:all';
            case 'users':
                return 'role:user';
            case 'guests':
                return 'role:guest';
        }

        return $permSelector;
    }

    protected function downgradePermissions(array $permissions)
    {
        $result = [
            'read' => [],
            'write' => []
        ];

        $splitPermissions = [];

        // split up the permisisons
        foreach ($permissions as $permission) {
            $permission_type = explode('(', $permission)[0];
            $permission_value = explode(')', explode('(', $permission)[1])[0];
            $splitPermissions[$permission_type][] = $permission_value;
        }

        // downgrade the permissions
        foreach ($permissions as $permission) {
            // permission = "read('any')" = ["read" => "role:all"]
            $permission_type = explode('(', $permission)[0];
            $permission_value = explode(')', explode('(', $permission)[1])[0];

            // Old type permissions meant that 'write' is equivalent to 'create', 'update' and 'delete'
            switch ($permission_type)
            {
                case 'update':
                case 'delete':
                case 'write':
                case 'create':
                    if (!in_array(downgradePermissionSelector($permission_value), $result['write'])) {
                        $result['write'][] = downgradePermissionSelector($permission_value);
                    }
                    break;
                case 'read':
                    if (!in_array(downgradePermissionSelector($permission_value), $result['read'])) {
                        $result['read'][] = downgradePermissionSelector($permission_value);
                    }
                    break;                
            }
        }

        return $result;
    }
}
