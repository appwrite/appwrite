<?php

namespace Appwrite\Utopia\Request\Filters;

use Appwrite\Utopia\Request\Filter;

class V21 extends Filter
{
    // Convert 1.8.0 params to 1.8.1
    public function parse(array $content, string $model): array
    {
        switch ($model) {
            case 'functions.createTemplateDeployment':
            case 'sites.createTemplateDeployment':
                $content = $this->convertVersionToTypeAndReference($content);
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
}
