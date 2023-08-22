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

    protected function parseVariable(array $content)
    {
        if (isset($content['resourceId'])) {
            $content['functionId'] = $content['resourceId'];
            unset($content['resourceId']);
        }

        return $content;
    }
}
