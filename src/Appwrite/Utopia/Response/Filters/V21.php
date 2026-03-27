<?php

namespace Appwrite\Utopia\Response\Filters;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Filter;

// Convert 1.9 Data format to 1.8 format
class V21 extends Filter
{
    public function parse(array $content, string $model): array
    {
        return match ($model) {
            Response::MODEL_PLATFORM_WEB => $this->parsePlatform($content),
            Response::MODEL_PLATFORM_APP => $this->parsePlatform($content),
            Response::MODEL_PLATFORM_APPLE => $this->parsePlatform($content),
            Response::MODEL_PLATFORM_ANDROID => $this->parsePlatform($content),
            Response::MODEL_PLATFORM_WINDOWS => $this->parsePlatform($content),
            Response::MODEL_PLATFORM_LINUX => $this->parsePlatform($content),
            Response::MODEL_PLATFORM_LIST => $this->handleList(
                $content,
                "platforms",
                fn ($item) => $this->parsePlatform($item),
            ),
            Response::MODEL_SITE => $this->parseSite($content),
            Response::MODEL_SITE_LIST => $this->handleList(
                $content,
                "sites",
                fn ($item) => $this->parseSite($item),
            ),
            Response::MODEL_FUNCTION => $this->parseFunction($content),
            Response::MODEL_FUNCTION_LIST => $this->handleList(
                $content,
                "functions",
                fn ($item) => $this->parseFunction($item),
            ),
            Response::MODEL_DOCUMENT => $this->parseDocument($content),
            Response::MODEL_DOCUMENT_LIST => $this->handleList(
                $content,
                "documents",
                fn ($item) => $this->parseDocument($item),
            ),
            Response::MODEL_ROW => $this->parseRow($content),
            Response::MODEL_ROW_LIST => $this->handleList(
                $content,
                "rows",
                fn ($item) => $this->parseRow($item),
            ),
            default => $content,
        };
    }

    protected function parseSite(array $content): array
    {
        $content = $this->parseSpecs($content);
        return $content;
    }

    protected function parsePlatform(array $content): array
    {
        // Map platform-specific identifier fields back to 'key'
        $content['key'] = $content['bundleIdentifier']
            ?? $content['applicationId']
            ?? $content['packageIdentifierName']
            ?? $content['packageName']
            ?? $content['identifier']
            ?? $content['key']
            ?? '';
        unset($content['bundleIdentifier']);
        unset($content['applicationId']);
        unset($content['packageIdentifierName']);
        unset($content['packageName']);
        unset($content['identifier']);

        // Restore fields removed in v1.9
        $content['store'] = $content['store'] ?? '';
        $content['hostname'] = $content['hostname'] ?? '';

        return $content;
    }

    protected function parseFunction(array $content): array
    {
        $content = $this->parseSpecs($content);
        return $content;
    }

    protected function parseSpecs(array $content): array
    {
        $content['specification'] = $content['buildSpecification'] ?? $content['specification'] ?? null;
        unset($content['buildSpecification']);
        unset($content['runtimeSpecification']);
        return $content;
    }

    protected function parseDocument(array $content): array
    {
        return $this->castSequence($content);
    }

    protected function parseRow(array $content): array
    {
        return $this->castSequence($content);
    }

    protected function castSequence(array $content): array
    {
        if (isset($content['$sequence'])) {
            $content['$sequence'] = \is_numeric($content['$sequence'])
                ? (int)$content['$sequence']
                : 0;
        }

        foreach ($content as $key => $value) {
            if (\is_array($value)) {
                if (isset($value['$id'])) {
                    $content[$key] = $this->castSequence($value);
                } else {
                    foreach ($value as $i => $item) {
                        if (\is_array($item) && isset($item['$id'])) {
                            $content[$key][$i] = $this->castSequence($item);
                        }
                    }
                }
            }
        }

        return $content;
    }
}
