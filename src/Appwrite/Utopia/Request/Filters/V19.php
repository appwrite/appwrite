<?php

namespace Appwrite\Utopia\Request\Filters;

use Appwrite\Utopia\Request\Filter;

class V19 extends Filter
{
    // Convert 1.6 params to 1.7
    public function parse(array $content, string $model): array
    {
        switch ($model) {
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
}
