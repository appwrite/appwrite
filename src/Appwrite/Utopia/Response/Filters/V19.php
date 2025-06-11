<?php

namespace Appwrite\Utopia\Response\Filters;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Filter;

class V19 extends Filter
{
    // Convert 1.7 Data format to 1.6 format
    public function parse(array $content, string $model): array
    {
        $parsedResponse = $content;

        $parsedResponse = match($model) {
            Response::MODEL_FUNCTION => $this->parseFunction($content),
            Response::MODEL_FUNCTION_LIST => $this->handleList($content, 'functions', fn ($item) => $this->parseFunction($item)),
            Response::MODEL_DEPLOYMENT => $this->parseDeployment($content),
            Response::MODEL_DEPLOYMENT_LIST => $this->handleList($content, 'deployments', fn ($item) => $this->parseDeployment($item)),
            Response::MODEL_PROXY_RULE => $this->parseProxyRule($content),
            Response::MODEL_PROXY_RULE_LIST => $this->handleList($content, 'rules', fn ($item) => $this->parseProxyRule($item)),
            Response::MODEL_MIGRATION => $this->parseMigration($content),
            Response::MODEL_MIGRATION_LIST => $this->handleList($content, 'migrations', fn ($item) => $this->parseMigration($item)),
            Response::MODEL_PROJECT => $this->parseProject($content),
            Response::MODEL_PROJECT_LIST => $this->handleList($content, 'projects', fn ($item) => $this->parseProject($item)),
            Response::MODEL_PROVIDER_REPOSITORY => $this->parseProviderRepository($content),
            Response::MODEL_TEMPLATE_VARIABLE => $this->parseTemplateVariable($content),
            Response::MODEL_USAGE_FUNCTION => $this->parseUsageFunction($content),
            Response::MODEL_USAGE_FUNCTIONS => $this->parseUsageFunctions($content),
            Response::MODEL_VARIABLE => $this->parseVariable($content),
            Response::MODEL_VARIABLE_LIST => $this->handleList($content, 'variables', fn ($item) => $this->parseVariable($item)),
            default => $parsedResponse,
        };

        return $parsedResponse;
    }

    protected function parseFunction(array $content): array
    {
        $content['deployment'] = $content['deploymentId'] ?? '';
        unset($content['deploymentId']);
        unset($content['deploymentCreatedAt']);
        unset($content['latestDeploymentId']);
        unset($content['latestDeploymentCreatedAt']);
        unset($content['latestDeploymentStatus']);
        return $content;
    }

    protected function parseDeployment(array $content)
    {
        $content['size'] = $content['sourceSize'] ?? '';
        $content['buildTime'] = $content['buildDuration'] ?? '';
        unset($content['sourceSize']);
        unset($content['buildDuration']);
        unset($content['totalSize']);
        unset($content['screenshotLight']);
        unset($content['screenshotDark']);
        return $content;
    }

    protected function parseProxyRule(array $content)
    {
        $content['resourceType'] = $content['deploymentResourceType'] ?? '';
        $content['resourceId'] = $content['deploymentResourceId'] ?? '';
        unset($content['deploymentResourceType']);
        unset($content['deploymentResourceId']);
        unset($content['type']);
        unset($content['trigger']);
        unset($content['triggerData']);
        unset($content['redirectStatusCode']);
        unset($content['deploymentId']);
        unset($content['deploymentVcsProviderBranch']);
        return $content;
    }

    protected function parseMigration(array $content)
    {
        unset($content['resourceId']);
        return $content;
    }

    protected function parseProject(array $content)
    {
        unset($content['devKeys']);
        return $content;
    }

    protected function parseProviderRepository(array $content)
    {
        unset($content['runtime']);
        return $content;
    }

    protected function parseTemplateVariable(array $content)
    {
        unset($content['secret']);
        return $content;
    }

    protected function parseUsageFunction(array $content)
    {
        unset($content['buildsSuccessTotal']);
        unset($content['buildsFailedTotal']);
        unset($content['buildsTimeAverage']);
        unset($content['buildsSuccess']);
        unset($content['buildsFailed']);
        return $content;
    }

    protected function parseUsageFunctions(array $content)
    {
        unset($content['buildsSuccessTotal']);
        unset($content['buildsFailedTotal']);
        unset($content['buildsSuccess']);
        unset($content['buildsFailed']);
        return $content;
    }

    protected function parseVariable(array $content)
    {
        unset($content['secret']);
        return $content;
    }
}
