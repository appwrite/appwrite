<?php

namespace Appwrite\Utopia\Response\Filters;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Filter;

class V20 extends Filter
{
    // removing $sequence from all versions less than 1.8
    public function parse(array $content, string $model): array
    {
        $parsedResponse = $content;

        return match($model) {
            Response::MODEL_DOCUMENT => $this->parseDocument($content),
            Response::MODEL_DOCUMENT_LIST => $this->handleList($content, 'documents', fn ($item) => $this->parseDocument($item)),
            default => $parsedResponse,
        };
    }

    protected function parseDocument(array $content): array
    {
        return $content;
    }
}
