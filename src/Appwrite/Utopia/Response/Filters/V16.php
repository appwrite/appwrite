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

        $parsedResponse = match($model) {
            Response::MODEL_DEPLOYMENT => $this->parseDeployment($parsedResponse),
            Response::MODEL_DEPLOYMENT_LIST => $this->handleList($content, 'deployments', fn ($item) => $this->parseDeployment($item)),
            Response::MODEL_EXECUTION => $this->parseExecution($parsedResponse),
            Response::MODEL_EXECUTION_LIST => $this->handleList($content, 'executions', fn ($item) => $this->parseExecution($item)),
            Response::MODEL_FUNCTION => $this->parseFunction($parsedResponse),
            Response::MODEL_FUNCTION_LIST => $this->handleList($content, 'functions', fn ($item) => $this->parseFunction($item)),
            Response::MODEL_PROJECT => $this->parseProject($parsedResponse),
            Response::MODEL_PROJECT_LIST => $this->handleList($content, 'projects', fn ($item) => $this->parseProject($item)),
            Response::MODEL_VARIABLE => $this->parseVariable($parsedResponse),
            Response::MODEL_VARIABLE_LIST => $this->handleList($content, 'variables', fn ($item) => $this->parseVariable($item)),
            default => $parsedResponse,
        };

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
        foreach ($content['oAuthProviders'] ?? [] as $i => $provider) {
            $content['oAuthProviders'][$i]['name'] = \ucfirst($provider['key']);
            unset($content['oAuthProviders'][$i]['key']);
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
