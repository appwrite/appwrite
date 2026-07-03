<?php

namespace Appwrite\Utopia\Response\Filters;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Filter;

// Convert 1.9.1 Data format to 1.9.0 format
class V22 extends Filter
{
    public function parse(array $content, string $model): array
    {
        return match ($model) {
            Response::MODEL_PROJECT => $this->parseProject($content),
            Response::MODEL_WEBHOOK => $this->parseWebhook($content),
            Response::MODEL_WEBHOOK_LIST => $this->handleList($content, 'webhooks', fn ($item) => $this->parseWebhook($item)),
            default => $content,
        };
    }

    private function parseProject(array $content): array
    {
        foreach (['protocolStatusForRest', 'protocolStatusForGraphql', 'protocolStatusForWebsocket'] as $field) {
            unset($content[$field]);
        }
        return $content;
    }

    private function parseWebhook(array $content): array
    {
        $content['security'] = $content['tls'] ?? true;
        unset($content['tls']);

        $content['httpUser'] = $content['authUsername'] ?? '';
        unset($content['authUsername']);

        $content['httpPass'] = $content['authPassword'] ?? '';
        unset($content['authPassword']);

        $content['signatureKey'] = $content['secret'] ?? '';
        unset($content['secret']);

        return $content;
    }
}
