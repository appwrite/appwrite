<?php

namespace Appwrite\Utopia\Request\Filters;

use Appwrite\Utopia\Request\Filter;

class V14 extends Filter
{
    // Convert 0.13 params format to 0.14 format
    public function parse(array $content, string $model): array
    {
        switch ($model) {
            case "functions.create":
            case "functions.update":
            case "projects.createWebhook":
            case "projects.updateWebhook":
                $content = $this->convertEvents($content);
                break;
        }

        return $content;
    }

    private function convertEvents($content)
    {
        // TODO: Convert events
        return $content;
    }
}
