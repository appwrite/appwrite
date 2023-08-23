<?php

namespace Appwrite\Utopia\Response\Filters;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Filter;

class V11 extends Filter
{
    // Convert 0.12 Data format to 0.11 format
    public function parse(array $content, string $model): array
    {
        $parsedResponse = $content;

        switch ($model) {
            // Update permissions
            case Response::MODEL_DOCUMENT:
                $parsedResponse = $this->parsePermissions($content);
                break;
            case Response::MODEL_DOCUMENT_LIST:
                $parsedResponse = $this->parseDocumentList($content);
                break;

            case Response::MODEL_FILE:
                $parsedResponse = $this->parsePermissions($content);
                break;
            case Response::MODEL_FILE_LIST:
                $parsedResponse = $this->parseFileList($content);
                break;

            case Response::MODEL_EXECUTION:
                $parsedResponse = $this->parseExecutionPermissions($content);
                break;
            case Response::MODEL_EXECUTION_LIST:
                $parsedResponse = $this->parseExecutionsList($content);
                break;

            case Response::MODEL_FUNCTION:
                $parsedResponse = $this->parseFunctionPermissions($content);
                break;
            case Response::MODEL_FUNCTION_LIST:
                $parsedResponse = $this->parseFunctionsList($content);
                break;

            // Convert status from boolean to int
            case Response::MODEL_USER:
                $parsedResponse = $this->parseStatus($content);
                break;
            case Response::MODEL_USER_LIST:
                $parsedResponse = $this->parseUserList($content);
                break;

            // Convert all Health responses back to original
            case Response::MODEL_HEALTH_STATUS:
                $parsedResponse = $this->parseHealthStatus($content);
                break;
            case Response::MODEL_HEALTH_VERSION:
                $parsedResponse = $this->parseHealthVersion($content);
                break;
            case Response::MODEL_HEALTH_TIME:
                $parsedResponse = $this->parseHealthTime($content);
                break;
            case Response::MODEL_HEALTH_QUEUE:
                $parsedResponse = $this->parseHealthQueue($content);
                break;
            case Response::MODEL_HEALTH_ANTIVIRUS:
                $parsedResponse = $this->parseHealthAntivirus($content);
                break;

            // Complex filters
            case Response::MODEL_COLLECTION:
                $parsedResponse = $this->parseCollection($content);
                break;
            case Response::MODEL_COLLECTION_LIST:
                $parsedResponse = $this->parseCollectionList($content);
                break;

            case Response::MODEL_LOG:
                $parsedResponse = $this->parseLog($content);
                break;
            case Response::MODEL_LOG_LIST:
                $parsedResponse = $this->parseLogList($content);
                break;

            case Response::MODEL_PROJECT:
                $parsedResponse = $this->parseProject($content);
                break;
            case Response::MODEL_PROJECT_LIST:
                $parsedResponse = $this->parseProjectList($content);
                break;
        }

        return $parsedResponse;
    }

    protected function parseDocumentList(array $content)
    {
        $documents = $content['documents'];
        $parsedResponse = [];
        foreach ($documents as $document) {
            $parsedResponse[] = $this->parsePermissions($document);
        }
        $content['documents'] = $parsedResponse;
        return $content;
    }

    protected function parseFileList(array $content)
    {
        $files = $content['files'];
        $parsedResponse = [];
        foreach ($files as $file) {
            $parsedResponse[] = $this->parsePermissions($file);
        }
        $content['files'] = $parsedResponse;
        return $content;
    }

    protected function parseExecutionsList(array $content)
    {
        $executions = $content['executions'];
        $parsedResponse = [];
        foreach ($executions as $execution) {
            $parsedResponse[] = $this->parseExecutionPermissions($execution);
        }
        $content['executions'] = $parsedResponse;
        return $content;
    }

    protected function parseFunctionsList(array $content)
    {
        $functions = $content['functions'];
        $parsedResponse = [];
        foreach ($functions as $function) {
            $parsedResponse[] = $this->parseFunctionPermissions($function);
        }
        $content['functions'] = $parsedResponse;
        return $content;
    }

    protected function parseUserList(array $content)
    {
        $users = $content['users'];
        $parsedResponse = [];
        foreach ($users as $user) {
            $parsedResponse[] = $this->parseStatus($user);
        }
        $content['users'] = $parsedResponse;
        return $content;
    }

    protected function parseCollection(array $content)
    {
        $parsedResponse = [];
        $parsedResponse = $this->parsePermissions($content);
        $parsedResponse = $this->removeRule($content, 'enabled');
        $parsedResponse = $this->removeRule($content, 'permission');
        $parsedResponse = $this->removeRule($content, 'indexes');
        $parsedResponse = $this->removeRule($content, 'enabled');
        $parsedResponse = $this->addDate($content, 'dateCreated');
        $parsedResponse = $this->addDate($content, 'dateUpdated');
        $parsedResponse = $this->parseAttributes($content);
        return $parsedResponse;
    }

    protected function parseCollectionList(array $content)
    {
        $collections = $content['collections'];
        $parsedResponse = [];
        foreach ($collections as $collection) {
            $parsedResponse[] = $this->parseCollection($collection);
        }
        $content['collections'] = $parsedResponse;
        return $content;
    }

    protected function parseLog(array $content)
    {
        $parsedResponse = [];
        $parsedResponse = $this->removeRule($content, 'userId');
        $parsedResponse = $this->removeRule($content, 'userEmail');
        $parsedResponse = $this->removeRule($content, 'userName');
        $parsedResponse = $this->removeRule($content, 'mode');
        $parsedResponse = $this->removeRule($content, 'sum');
        return $parsedResponse;
    }

    protected function parseLogList(array $content)
    {
        $logs = $content['logs'];
        $parsedResponse = [];
        foreach ($logs as $log) {
            $parsedResponse[] = $this->parseLog($log);
        }
        $content['logs'] = $parsedResponse;
        return $content;
    }

    protected function parseProject(array $content)
    {
        $parsedResponse = [];
        $parsedResponse = $this->addTasks($content);
        $parsedResponse = $this->parseAuthLimit($content);
        $parsedResponse = $this->parseOAuths($content);
        $parsedResponse = $this->parseAuthsStatus($content);
        $parsedResponse = $this->removeServicesStatus($content);
        return $parsedResponse;
    }

    protected function parseProjectList(array $content)
    {
        $projects = $content['projects'];
        $parsedResponse = [];
        foreach ($projects as $project) {
            $parsedResponse[] = $this->parseProject($project);
        }
        $content['projects'] = $parsedResponse;
        return $content;
    }

    protected function parseHealthAntivirus(array $content)
    {
        if ($content['status'] === 'pass') {
            $content['status'] = 'online';
        }

        if ($content['status'] === 'fail') {
            $content['status'] = 'offline';
        }

        return $content;
    }

    protected function parseHealthTime(array $content)
    {
        $content['remote'] = $content['remoteTime'];
        unset($content['remoteTime']);

        $content['local'] = $content['localTime'];
        unset($content['localTime']);

        return $content;
    }

    protected function parseHealthVersion(array $content)
    {
        // Did not change

        return $content;
    }


    protected function parseHealthQueue(array $content)
    {
        // Did not change

        return $content;
    }

    protected function parseHealthStatus(array $content)
    {
        $content['status'] = 'OK'; // Is always returning pass, was always returning OK
        unset($content['ping']);

        return $content;
    }

    protected function parseStatus(array $content)
    {
        $content['status'] = $content['status'] === true ?
            $content['emailVerification'] === true ? 1 : 0
            : 2;

        return $content;
    }

    protected function parseAttributes(array $content)
    {
        $content['rules'] = \array_map(function ($attribute) use ($content) {
            return [
                '$id' => $attribute['key'],
                '$collection' => ID::custom($content['$id']),
                'type' => $attribute['type'],
                'key' => $attribute['key'],
                'label' => $attribute['key'],
                'default' => $attribute['default'],
                'array' => $attribute['array'],
                'required' => $attribute['required'],
                'list' => $attribute['elements'],
            ];
        }, $content['attributes']);
        unset($content['attributes']);

        return $content;
    }

    protected function parseAuthLimit(array $content)
    {
        $content['usersAuthLimit'] = $content['authLimit'];
        unset($content['authLimit']);

        return $content;
    }

    protected function parseOAuths(array $content)
    {
        $regexPattern = "/provider([a-zA-Z0-9]+)(Appid|Secret)/";

        foreach ($content as $key => $value) {
            \preg_match_all($regexPattern, $key, $regexGroups);
            if (\count($regexGroups[1]) > 0 && \count($regexGroups[2]) > 0) {
                $providerName = $regexGroups[1][0];
                $valueKey = $regexGroups[2][0];
                $content['usersOauth2' . $providerName . $valueKey] = $value;
                unset($content['provider' . $providerName . $valueKey]);
            }
        }

        return $content;
    }

    protected function parseAuthsStatus(array $content)
    {
        $regexPattern = "/auth([a-zA-Z0-9]+)/";

        foreach ($content as $key => $value) {
            \preg_match_all($regexPattern, $key, $regexGroups);
            if (\count($regexGroups[1]) > 0) {
                $providerName = $regexGroups[1][0];

                $content[$providerName] = $value;
                unset($content['auth' . $providerName]);
            }
        }

        return $content;
    }

    protected function removeServicesStatus(array $content)
    {
        // Such a key is part of new response, but is not part of old one. We simply remove it, older version never
        // expected it anyway.
        foreach ($content as $key => $value) {
            if (\str_starts_with($key, 'serviceStatusFor')) {
                unset($content[$key]);
            }
        }

        return $content;
    }

    protected function removeRule(array $content, $key)
    {
        // Such a key is part of new response, but is not part of old one. We simply remove it, older version never
        // expected it anyway.
        unset($content[$key]);

        return $content;
    }

    protected function addDate(array $content, $key)
    {
        // We simply don't have the date available in the content anymore.
        // We set it to valid integer that indicates the value is not right
        $content[$key] = 0;

        return $content;
    }

    protected function addTasks(array $content)
    {
        // We simply don't have the date available in the content anymore.
        // We set it to valid array
        $content['tasks'] = [];

        return $content;
    }

    protected function parsePermissions(array $content)
    {
        $content['$permissions'] = [ 'read' => $content['$read'], 'write' => $content['$write'] ];
        unset($content['$read']);
        unset($content['$write']);
        return $content;
    }

    protected function parseFunctionPermissions(array $content)
    {
        $content['$permissions'] = [ 'execute' => $content['execute'] ];
        unset($content['execute']);

        return $content;
    }

    protected function parseExecutionPermissions(array $content)
    {
        $content['$permissions'] = [ 'read' => $content['$read'] ];
        unset($content['$read']);

        return $content;
    }
}
