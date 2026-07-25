<?php

namespace Appwrite\Utopia\Request\Filters;

use Appwrite\Utopia\Request\Filter;

class V24 extends Filter
{
    // Convert 1.9.2 params to 1.9.3
    public function parse(array $content, string $model): array
    {
        switch ($model) {
            case 'project.createStandardKey':
                $content = $this->fillKeyId($content);
                $content = $this->parseKeyScopes($content);
                break;
        }

        return $content;
    }

    protected function fillKeyId(array $content): array
    {
        $content['keyId'] = $content['keyId'] ?? 'unique()';
        return $content;
    }

    protected function parseKeyScopes(array $content): array
    {
        if (!\is_array($content['scopes'] ?? null)) {
            $content['scopes'] = [];
        }

        return $content;
    }
}
