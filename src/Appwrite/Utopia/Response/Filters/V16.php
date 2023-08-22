<?php

namespace Appwrite\Utopia\Response\Filters;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Filter;
use Cron\CronExpression;
use Utopia\Database\DateTime;

class V16 extends Filter
{
    // Convert 1.4 Data format to 1.3 format
    public function parse(array $content, string $model): array
    {
        $parsedResponse = $content;

        switch ($model) {
            case Response::MODEL_DEPLOYMENT:
                $parsedResponse = $this->parseDeployment($parsedResponse);
                break;
            case Response::MODEL_PROXY_RULE:
                // We won't be supporting the domain endpoints for older SDKs
                // since these APIs are internal. As such, no filtering required
                break;
            case Response::MODEL_EXECUTION:
                $parsedResponse = $this->parseExecution($parsedResponse);
                break;
            case Response::MODEL_FUNCTION:
                $parsedResponse = $this->parseFunction($parsedResponse);
                break;
            case Response::MODEL_PROJECT:
                $parsedResponse = $this->parseProject($parsedResponse);
                break;
            // We've decided to push these usage changes to a future release so
            // these changes may still be helpful down the line.
            // case Response::MODEL_USAGE_BUCKETS:
            //     $parsedResponse = $this->parseUsageBuckets($parsedResponse);
            //     break;
            // case Response::MODEL_USAGE_COLLECTION:
            //     $parsedResponse = $this->parseUsageCollection($parsedResponse);
            //     break;
            // case Response::MODEL_USAGE_DATABASE:
            //     $parsedResponse = $this->parseUsageDatabase($parsedResponse);
            //     break;
            // case Response::MODEL_USAGE_DATABASES:
            //     $parsedResponse = $this->parseUsageDatabases($parsedResponse);
            //     break;
            // case Response::MODEL_USAGE_FUNCTION:
            //     $parsedResponse = $this->parseUsageFunction($parsedResponse);
            //     break;
            // case Response::MODEL_USAGE_FUNCTIONS:
            //     $parsedResponse = $this->parseUsageFunctions($parsedResponse);
            //     break;
            // case Response::MODEL_USAGE_PROJECT:
            //     $parsedResponse = $this->parseUsageProject($parsedResponse);
            //     break;
            // case Response::MODEL_USAGE_STORAGE:
            //     $parsedResponse = $this->parseUsageStorage($parsedResponse);
            //     break;
            // case Response::MODEL_USAGE_USERS:
            //     $parsedResponse = $this->parseUsageUsers($parsedResponse);
            //     break;
            case Response::MODEL_VARIABLE:
                $parsedResponse = $this->parseVariable($parsedResponse);
                break;
        }

        return $parsedResponse;
    }

    protected function parseDeployment(array $content)
    {
        $content['buildStderr'] = '';
        $content['buildStdout'] = $content['buildLogs'];
        unset($content['buildLogs']);
        return $content;
    }

    protected function parseExecution(array $content)
    {
        if (isset($content['responseStatusCode'])) {
            $content['statusCode'] = $content['responseStatusCode'];
            unset($content['responseStatusCode']);
        }

        if (isset($content['responseBody'])) {
            $content['response'] = $content['responseBody'];
            unset($content['responseBody']);
        }

        if (isset($content['logs'])) {
            $content['stdout'] = $content['logs'];
            unset($content['logs']);
        }

        if (isset($content['errors'])) {
            $content['stderr'] = $content['errors'];
            unset($content['errors']);
        }

        return $content;
    }

    protected function parseFunction(array $content)
    {
        $content['schedulePrevious'] = '';
        $content['scheduleNext'] = '';

        if (!empty($content['schedule'])) {
            $cron = new CronExpression($content['schedule']);
            $content['schedulePrevious'] = DateTime::formatTz(DateTime::format($cron->getPreviousRunDate()));
            $content['scheduleNext'] = DateTime::formatTz(DateTime::format($cron->getNextRunDate()));
        }

        return $content;
    }

    protected function parseProject(array $content)
    {
        foreach ($content['providers'] ?? [] as $i => $provider) {
            $content['providers'][$i]['name'] = \ucfirst($provider['key']);
            unset($content['providers'][$i]['key']);
        }

        $content['domains'] = [];
        return $content;
    }

    protected function parseUsageBuckets(array $content)
    {
        if (isset($content['filesTotal'])) {
            $content['filesCount'] = $content['filesTotal'];
            unset($content['filesTotal']);
        }

        $attributesToInit = ['filesCreate', 'filesRead', 'filesUpdate', 'filesDelete'];
        foreach ($attributesToInit as $attribute) {
            $content[$attribute] = [];
        }

        return $content;
    }

    protected function parseUsageCollection(array $content)
    {
        if (isset($content['documentsTotal'])) {
            $content['documentsCount'] = $content['documentsTotal'];
            unset($content['documentsTotal']);
        }

        $attributesToInit = ['documentsCreate', 'documentsRead', 'documentsUpdate', 'documentsDelete'];
        foreach ($attributesToInit as $attribute) {
            $content[$attribute] = [];
        }

        return $content;
    }

    protected function parseUsageDatabase(array $content)
    {
        if (isset($content['documentsTotal'])) {
            $content['documentsCount'] = $content['documentsTotal'];
            unset($content['documentsTotal']);
        }

        if (isset($content['collectionsTotal'])) {
            $content['collectionsCount'] = $content['collectionsTotal'];
            unset($content['collectionsTotal']);
        }

        $attributesToInit = [
            'documentsCreate',
            'documentsRead',
            'documentsUpdate',
            'documentsDelete',
            'collectionsCreate',
            'collectionsRead',
            'collectionsUpdate',
            'collectionsDelete',
        ];
        foreach ($attributesToInit as $attribute) {
            $content[$attribute] = [];
        }

        return $content;
    }

    protected function parseUsageDatabases(array $content)
    {
        if (isset($content['documentsTotal'])) {
            $content['documentsCount'] = $content['documentsTotal'];
            unset($content['documentsTotal']);
        }

        if (isset($content['collectionsTotal'])) {
            $content['collectionsCount'] = $content['collectionsTotal'];
            unset($content['collectionsTotal']);
        }

        if (isset($content['databasesTotal'])) {
            $content['databasesCount'] = $content['databasesTotal'];
            unset($content['databasesTotal']);
        }

        $attributesToInit = [
            'documentsCreate',
            'documentsRead',
            'documentsUpdate',
            'documentsDelete',
            'collectionsCreate',
            'collectionsRead',
            'collectionsUpdate',
            'collectionsDelete',
            'databasesCreate',
            'databasesRead',
            'databasesUpdate',
            'databasesDelete',
        ];
        foreach ($attributesToInit as $attribute) {
            $content[$attribute] = [];
        }

        return $content;
    }

    protected function parseUsageFunction(array $content)
    {
        $attributesToInit = [
            'buildsFailure',
            'buildsSuccess',
            'executionsFailure',
            'executionsSuccess',
        ];
        foreach ($attributesToInit as $attribute) {
            $content[$attribute] = [];
        }

        return $content;
    }

    protected function parseUsageFunctions(array $content)
    {
        $attributesToInit = [
            'buildsFailure',
            'buildsSuccess',
            'executionsFailure',
            'executionsSuccess',
        ];
        foreach ($attributesToInit as $attribute) {
            $content[$attribute] = [];
        }

        return $content;
    }

    protected function parseUsageProject(array $content)
    {
        if (isset($content['requestsTotal'])) {
            $content['requests'] = $content['requestsTotal'];
            unset($content['requestsTotal']);
        }

        if (isset($content['executionsTotal'])) {
            $content['executions'] = $content['executionsTotal'];
            unset($content['executionsTotal']);
        }

        if (isset($content['documentsTotal'])) {
            $content['documents'] = $content['documentsTotal'];
            unset($content['documentsTotal']);
        }

        if (isset($content['databasesTotal'])) {
            $content['databases'] = $content['databasesTotal'];
            unset($content['databasesTotal']);
        }

        if (isset($content['usersTotal'])) {
            $content['users'] = $content['usersTotal'];
            unset($content['usersTotal']);
        }

        if (isset($content['filesStorage'])) {
            $content['storage'] = $content['filesStorage'];
            unset($content['filesStorage']);
        }

        if (isset($content['bucketsTotal'])) {
            $content['buckets'] = $content['bucketsTotal'];
            unset($content['bucketsTotal']);
        }

        return $content;
    }

    protected function parseUsageStorage(array $content)
    {
        if (isset($content['bucketsTotal'])) {
            $content['bucketsCount'] = $content['bucketsTotal'];
            unset($content['bucketsTotal']);
        }

        if (isset($content['filesTotal'])) {
            $content['filesCount'] = $content['filesTotal'];
            unset($content['filesTotal']);
        }

        if (isset($content['filesStorage'])) {
            $content['storage'] = $content['filesStorage'];
            unset($content['filesStorage']);
        }

        $attributesToInit = [
            'bucketsCreate',
            'bucketsRead',
            'bucketsUpdate',
            'bucketsDelete',
            'filesCreate',
            'filesRead',
            'filesUpdate',
            'filesDelete',
        ];
        foreach ($attributesToInit as $attribute) {
            $content[$attribute] = [];
        }

        return $content;
    }

    protected function parseUsageUsers(array $content)
    {
        if (isset($content['usersTotal'])) {
            $content['usersCount'] = $content['usersTotal'];
            unset($content['usersTotal']);
        }

        if (isset($content['sessionsTotal'])) {
            $content['sessionsCreate'] = $content['sessionsTotal'];
            unset($content['sessionsTotal']);
        }

        $attributesToInit = [
            'usersCreate',
            'usersRead',
            'usersUpdate',
            'usersDelete',
            'sessionsProviderCreate',
            'sessionsDelete',
        ];
        foreach ($attributesToInit as $attribute) {
            $content[$attribute] = [];
        }

        return $content;
    }

    protected function parseVariable(array $content)
    {
        if (isset($content['resourceId'])) {
            $content['functionId'] = $content['resourceId'];
            unset($content['resourceId']);
        }

        return $content;
    }
}
