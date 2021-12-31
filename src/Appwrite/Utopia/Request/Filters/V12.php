<?php

namespace Appwrite\Utopia\Request\Filters;

use Appwrite\Utopia\Request\Filter;

class V12 extends Filter
{
    // Convert 0.11 params format to 0.12 format
    public function parse(array $content, string $model): array
    {
        $parsedResponse = [];

        switch ($model) {
            case "account.create":
                $parsedResponse = $this->addUserId($content);
                break;
        }

        if(empty($parsedResponse)) {
            // No changes between current version and the one user requested
            $parsedResponse = $content;
        }

        return $parsedResponse;
    }

    protected function addUserId(array $content): array
    {
        $content['userId'] = 'unique()';
        return $content;
    }
}
