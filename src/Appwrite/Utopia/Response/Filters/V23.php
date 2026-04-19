<?php

namespace Appwrite\Utopia\Response\Filters;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Filter;

// Convert 1.9.2 Data format to 1.9.1 format
class V23 extends Filter
{
    public function parse(array $content, string $model): array
    {
        return match ($model) {
            Response::MODEL_EMAIL_TEMPLATE => $this->parseEmailTemplate($content),
            default => $content,
        };
    }

    private function parseEmailTemplate(array $content): array
    {
        if (isset($content['templateId'])) {
            $content['type'] = $content['templateId'];
            unset($content['templateId']);
        }

        return $content;
    }
}
