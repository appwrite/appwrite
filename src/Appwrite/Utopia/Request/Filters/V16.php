<?php

namespace Appwrite\Utopia\Request\Filters;

use Appwrite\Utopia\Request\Filter;

class V16 extends Filter
{
    // Convert 1.0 params to 1.4
    public function parse(array $content, string $model): array
    {
        switch ($model) {
            case 'functions.create':
                // TODO: How to handle this?
                $content['entrypoint'] = ' ';
                break;
            case 'functions.update':
                // TODO: How to handle this?
                $content['runtime'] = ' ';
                break;
            case 'functions.createExecution':
                $content['body'] = $content['data'];
                unset($content['data']);
                break;
            case 'projects.createDomain':
                // TODO: How to handle this?
                // This endpoint was deleted
                break;
            case 'projects.listDomains':
                // TODO: How to handle this?
                // This endpoint was deleted
                break;
            case 'projects.getDomain':
                // TODO: How to handle this?
                // This endpoint was deleted
                break;
            case 'projects.updateDomainVerification':
                // TODO: How to handle this?
                // This endpoint was deleted
                break;
            case 'projects.deleteDomain':
                // TODO: How to handle this?
                // This endpoint was deleted
                break;
        }

        return $content;
    }
}
