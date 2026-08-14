<?php

namespace Appwrite\Utopia\Request\Filters;

use Appwrite\Utopia\Request\Filter;

class V26 extends Filter
{
    // Convert 1.9.4 params to 1.9.5
    public function parse(array $content, string $model): array
    {
        switch ($model) {
            case 'projects.create':
                $content = $this->stripProjectMetadata($content);
                break;
        }

        return $content;
    }

    protected function stripProjectMetadata(array $content): array
    {
        unset(
            $content['description'],
            $content['logo'],
            $content['url'],
            $content['legalName'],
            $content['legalCountry'],
            $content['legalState'],
            $content['legalCity'],
            $content['legalAddress'],
            $content['legalTaxId'],
        );

        return $content;
    }
}
