<?php

namespace Appwrite\Utopia\Request\Filters;

use Appwrite\Utopia\Request\Filter;

class V19 extends Filter
{
    // Convert 1.6 params to 1.7
    public function parse(array $content, string $model): array
    {
        switch ($model) {
            case 'functions.list':
                $content = $this->convertQueryAttribute($content, 'deployment', 'deploymentId');
                break;
            case 'functions.listDeployments':
                $content = $this->convertQueryAttribute($content, 'size', 'deploymentSize');
                break;
            case 'proxy.listRules':
                $content = $this->convertQueryAttribute($content, 'resourceType', 'deploymentResourceType');
                $content = $this->convertQueryAttribute($content, 'resourceId', 'deploymentResourceId');
                break;
            case 'functions.create':
                unset($content['templateRepository']);
                unset($content['templateOwner']);
                unset($content['templateRootDirectory']);
                unset($content['templateVersion']);
                break;
            case 'functions.listExecutions':
                unset($content['search']);
                break;
            case 'project.createVariable':
            case 'project.listVariables':
            case 'functions.createVariable':
            case 'functions.updateVariable':
                $content['secret'] = false;
                break;
            case 'functions.getDeploymentDownload':
                // Pre-1.7.0 clients call the legacy alias
                // `/v1/functions/:functionId/deployments/:deploymentId/build/download`,
                // which always downloaded the build output. The merged 1.7.0 endpoint
                // requires an explicit `type` param, so force it to `output` here.
                $content['type'] = 'output';
                break;
        }
        return $content;
    }

    public function convertQueryAttribute(array $content, string $old, string $new): array
    {
        if (isset($content['queries']) && is_array($content['queries'])) {
            foreach ($content['queries'] as $index => $query) {
                $query = \json_decode($query, true);
                if (($query['attribute'] ?? '') === $old) {
                    $query['attribute'] = $new;
                }
                $content['queries'][$index] = \json_encode($query);
            }
        }

        return $content;
    }
}
