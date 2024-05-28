<?php

namespace Appwrite\Utopia\Response\Filters;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Filter;

class V18 extends Filter
{
    // Convert 1.5.7 -> 1.5.8 data format
    public function parse(array $content, string $model): array
    {
        $parsedResponse = $content;

        $parsedResponse = match($model) {
            Response::MODEL_MIGRATION => $this->parseMigration($parsedResponse),
            default => $parsedResponse,
        };

        return $parsedResponse;
    }

    protected function parseMigration(array $content) {
        $content['stage'] = match($content['status']) {
            'pending' => 'init',
            'processing' => 'migrating',
            'completed' => 'finished',
            'failed' => 'finished',
            default => 'processing',
        };

        return $content;
    }
}