<?php

namespace Appwrite\Utopia\Request\Filters;

use Appwrite\Utopia\Request\Filter;

class V11 extends Filter
{
    // TODO: Should this class be called be V11 or V12?
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
            // TODO: Do we need execption? We dont need to find, right? Not found means no changes
            // throw new Exception('Received invalid request model : '. $model);
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
