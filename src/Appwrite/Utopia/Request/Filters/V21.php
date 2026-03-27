<?php

namespace Appwrite\Utopia\Request\Filters;

use Appwrite\Query;
use Appwrite\Utopia\Request\Filter;

class V21 extends Filter
{
    // Convert 1.8.0 params to 1.9.0
    public function parse(array $content, string $model): array
    {
        switch ($model) {
            case 'project.createWebPlatform':
                $content = $this->fillPlatformId($content);
                $content = $this->removePlatformStore($content);
                unset($content['key']); // Key unsupported
                break;
            case 'project.updateWebPlatform':
                $content = $this->removePlatformStore($content);
                unset($content['key']); // Key unsupported
                break;
            case 'project.createApplePlatform':
                $content = $this->fillPlatformId($content);
                $content = $this->removePlatformStore($content);
                $content = $this->replacePlatformKey($content, 'bundleIdentifier');
                unset($content['hostname']); // Hostname unsupported
                break;
            case 'project.updateApplePlatform':
                $content = $this->removePlatformStore($content);
                $content = $this->replacePlatformKey($content, 'bundleIdentifier');
                unset($content['hostname']); // Hostname unsupported
                break;
            case 'project.createAndroidPlatform':
                $content = $this->fillPlatformId($content);
                $content = $this->removePlatformStore($content);
                $content = $this->replacePlatformKey($content, 'applicationId');
                unset($content['hostname']); // Hostname unsupported
                break;
            case 'project.updateAndroidPlatform':
                $content = $this->removePlatformStore($content);
                $content = $this->replacePlatformKey($content, 'applicationId');
                unset($content['hostname']); // Hostname unsupported
                break;
            case 'project.createWindowsPlatform':
                $content = $this->fillPlatformId($content);
                $content = $this->removePlatformStore($content);
                $content = $this->replacePlatformKey($content, 'packageIdentifierName');
                unset($content['hostname']); // Hostname unsupported
                break;
            case 'project.updateWindowsPlatform':
                $content = $this->removePlatformStore($content);
                $content = $this->replacePlatformKey($content, 'packageIdentifierName');
                unset($content['hostname']); // Hostname unsupported
                break;
            case 'project.createLinuxPlatform':
                $content = $this->fillPlatformId($content);
                $content = $this->removePlatformStore($content);
                $content = $this->replacePlatformKey($content, 'packageName');
                unset($content['hostname']); // Hostname unsupported
                break;
            case 'project.updateLinuxPlatform':
                $content = $this->removePlatformStore($content);
                $content = $this->replacePlatformKey($content, 'packageName');
                unset($content['hostname']); // Hostname unsupported
                break;
            case 'project.listPlatforms':
                $content = $this->preservePlatformsQueries($content);
                break;
            case 'webhooks.create':
                $content = $this->fillWebhookid($content);
                break;
            case 'project.createVariable':
                $content = $this->fillVariableId($content);
                break;
            case 'project.listVariables':
                $content = $this->preserveVariablesQueries($content);
                break;
            case 'functions.createTemplateDeployment':
            case 'sites.createTemplateDeployment':
                $content = $this->convertVersionToTypeAndReference($content);
                break;
            case 'functions.create':
            case 'sites.create':
            case 'functions.update':
            case 'sites.update':
                $content = $this->convertSpecs($content);
                break;
        }
        return $content;
    }

    /**
     * Convert version parameter to type and reference for backwards compatibility
     * with 1.8.0 template deployment endpoints
     */
    protected function convertVersionToTypeAndReference(array $content): array
    {
        if (!empty($content['version'])) {
            $content['type'] = 'tag';
            $content['reference'] = $content['version'];
            unset($content['version']);
        }
        return $content;
    }

    protected function convertSpecs(array $content): array
    {
        if (!empty($content['specification'])) {
            $content['buildSpecification'] = $content['specification'];
            $content['runtimeSpecification'] = $content['specification'];
            unset($content['specification']);
        }

        return $content;
    }

    protected function fillWebhookid(array $content): array
    {
        $content['webhookId'] = $content['webhookId'] ?? 'unique()';
        return $content;
    }

    protected function fillPlatformId(array $content): array
    {
        $content['platformId'] = $content['platformId'] ?? 'unique()';
        return $content;
    }

    protected function replacePlatformKey(array $content, string $newKey): array
    {
        $content[$newKey] = $content[$newKey] ?? $content['key'] ?? null;
        unset($content['key']);

        return $content;
    }

    protected function removePlatformStore(array $content): array
    {
        unset($content['store']);
        return $content;
    }

    protected function fillVariableId(array $content): array
    {
        $content['variableId'] = $content['variableId'] ?? 'unique()';
        return $content;
    }

    protected function preserveVariablesQueries(array $content): array
    {
        $content['queries'] = $content['queries'] ?? [
            Query::limit(APP_LIMIT_SUBQUERY)
        ];

        return $content;
    }

    protected function preservePlatformsQueries(array $content): array
    {
        $content['queries'] = $content['queries'] ?? [
            Query::limit(5000)
        ];

        return $content;
    }
}
