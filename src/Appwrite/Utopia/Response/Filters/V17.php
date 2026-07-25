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

        $parsedResponse = match($model) {
            Response::MODEL_PROJECT => $this->parseProject($parsedResponse),
            Response::MODEL_PROJECT_LIST => $this->handleList($content, 'projects', fn ($item) => $this->parseProject($item)),
            Response::MODEL_USER => $this->parseUser($parsedResponse),
            Response::MODEL_USER_LIST => $this->handleList($content, 'users', fn ($item) => $this->parseUser($item)),
            Response::MODEL_MEMBERSHIP => $this->parseMembership($parsedResponse),
            Response::MODEL_MEMBERSHIP_LIST => $this->handleList($content, 'memberships', fn ($item) => $this->parseMembership($item)),
            Response::MODEL_SESSION => $this->parseSession($parsedResponse),
            Response::MODEL_SESSION_LIST => $this->handleList($content, 'sessions', fn ($item) => $this->parseSession($item)),
            Response::MODEL_WEBHOOK => $this->parseWebhook($parsedResponse),
            Response::MODEL_WEBHOOK_LIST => $this->handleList($content, 'webhooks', fn ($item) => $this->parseWebhook($item)),
            Response::MODEL_TOKEN => $this->parseToken($parsedResponse),
            default => $parsedResponse,
        };

        return $parsedResponse;
    }

    protected function parseUser(array $content)
    {
        unset($content['targets']);
        unset($content['mfa']);
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
