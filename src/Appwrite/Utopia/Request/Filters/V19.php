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
