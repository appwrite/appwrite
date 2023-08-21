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
            case 'projects.listDomains':
            case 'projects.getDomain':
            case 'projects.updateDomainVerification':
            case 'projects.deleteDomain':
                // These endpoints were deleted and we're accepting
                // the breaking change since the endpoint was only
                // used internally.
                break;
        }

        return $content;
    }
}
