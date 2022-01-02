<?php

namespace Appwrite\Utopia\Response\Filters;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Filter;
use Exception;

class V11 extends Filter
{
    // TODO: Health
    
    // Convert 0.12 Data format to 0.11 format
    public function parse(array $content, string $model): array
    {
        $parsedResponse = $content;

        switch ($model) {
            // Update permissions
            case Response::MODEL_DOCUMENT:
            case Response::MODEL_FILE:
                $parsedResponse = $this->parsePermissions($content);
                break;
            case Response::MODEL_EXECUTION:
                $parsedResponse = $this->parseExecutionPermissions($content);
                break;
            case Response::MODEL_FUNCTION:
                $parsedResponse = $this->parseFunctionPermissions($content);
                break;
            // Convert status from boolean to int
            case Response::MODEL_USER:
                $parsedResponse = $this->parseStatus($content);
                break;

            // Complex filters
            case Response::MODEL_COLLECTION:
                $parsedResponse = $this->parsePermissions($content);
                $parsedResponse = $this->removeRule($content, 'enabled');
                $parsedResponse = $this->removeRule($content, 'permission');
                $parsedResponse = $this->removeRule($content, 'indexes');
                $parsedResponse = $this->removeRule($content, 'enabled');
                $parsedResponse = $this->addDate($content, 'dateCreated');
                $parsedResponse = $this->addDate($content, 'dateUpdated');
                $parsedResponse = $this->parseAttributes($content);
            case Response::MODEL_LOG:
                $parsedResponse = $this->removeRule($content, 'userId');
                $parsedResponse = $this->removeRule($content, 'userEmail');
                $parsedResponse = $this->removeRule($content, 'userName');
                $parsedResponse = $this->removeRule($content, 'mode');
                $parsedResponse = $this->removeRule($content, 'sum');
            case Response::MODEL_PROJECT:
                $parsedResponse = $this->addTasks($content);
                $parsedResponse = $this->parseAuthLimit($content);
                $parsedResponse = $this->parseOAuths($content);
                $parsedResponse = $this->parseAuthsStatus($content);
                $parsedResponse = $this->removeServicesStatus($content);
                break;
        }

        return $parsedResponse;
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
        $content['rules'] = \array_map(function($attribute) use($content) {
            return [
                '$id' => $attribute['key'],
                '$collection' => $content['$id'],
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
            if(\count($regexGroups[1]) > 0 && \count($regexGroups[2]) > 0) {
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
            if(\count($regexGroups[1]) > 0) {
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
            if(\str_starts_with($key, 'serviceStatusFor')) {
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
