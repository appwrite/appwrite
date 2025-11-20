<?php

namespace Appwrite\Utopia\Response\Filters;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Filter;

// Convert 1.9 Data format to 1.8 format
class V21 extends Filter
{
    public function parse(array $content, string $model): array
    {
        $parsedResponse = $content;

        return match ($model) {
            Response::MODEL_SITE => $this->parseSite($content),
            Response::MODEL_SITE_LIST => $this->handleList(
                $content,
                "sites",
                fn ($item) => $this->parseSite($item),
            ),
            Response::MODEL_FUNCTION => $this->parseFunction($content),
            Response::MODEL_FUNCTION_LIST => $this->handleList(
                $content,
                "sites",
                fn ($item) => $this->parseFunction($item),
            ),
            default => $parsedResponse,
        };
    }

    protected function parseSite(array $content): array
    {
        $content = $this->parseSpecs($content);
        return $content;
    }

    protected function parseFunction(array $content): array
    {
        $content = $this->parseSpecs($content);
        return $content;
    }

    protected function parseSpecs(array $content): array
    {
        $content['specification'] = $content['buildSpecification'] ?? null;
        unset($content['buildSpecification']);
        unset($content['runtimeSpecification']);
        return $content;
    }
}
