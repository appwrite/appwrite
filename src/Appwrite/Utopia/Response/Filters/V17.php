<?php

namespace Appwrite\Utopia\Response\Filters;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Filter;

class V17 extends Filter
{
    // Convert 1.5 Data format to 1.4 format
    public function parse(array $content, string $model): array
    {
        $parsedResponse = $content;

        switch ($model) {
            case Response::MODEL_PROJECT:
                $parsedResponse = $this->parseProject($parsedResponse);
                break;
            case Response::MODEL_USER:
                $parsedResponse = $this->parseUser($parsedResponse);
                break;
            case Response::MODEL_TOKEN:
                $parsedResponse = $this->parseToken($parsedResponse);
                break;
        }

        return $parsedResponse;
    }

    protected function parseUser(array $content)
    {
        unset($content['targets']);
        return $content;
    }

    protected function parseProject(array $content)
    {
        $content['providers'] = $content['oAuthProviders'];
        unset($content['oAuthProviders']);
        return $content;
    }

    protected function parseToken(array $content)
    {
        unset($content['securityPhrase']);
        return $content;
    }
}