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
            case Response::MODEL_MEMBERSHIP:
                $parsedResponse = $this->parseMembership($parsedResponse);
                break;
            case Response::MODEL_SESSION:
                $parsedResponse = $this->parseSession($parsedResponse);
                break;
            case Response::MODEL_WEBHOOK:
                $parsedResponse = $this->parseWebhook($parsedResponse);
                break;
        }

        return $parsedResponse;
    }

    protected function parseUser(array $content)
    {
        unset($content['targets']);
        unset($content['mfa']);
        unset($content['totp']);
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
        unset($content['phrase']);
        return $content;
    }

    protected function parseMembership(array $content)
    {
        unset($content['mfa']);
        return $content;
    }

    protected function parseSession(array $content)
    {
        unset($content['factors']);
        unset($content['secret']);
        return $content;
    }

    protected function parseWebhook(array $content)
    {
        unset($content['enabled']);
        unset($content['logs']);
        unset($content['attempts']);
        return $content;
    }
}
