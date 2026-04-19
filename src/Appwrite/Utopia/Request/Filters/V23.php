<?php

namespace Appwrite\Utopia\Request\Filters;

use Appwrite\Utopia\Request\Filter;

class V23 extends Filter
{
    // Convert 1.9.1 params to 1.9.2
    protected function parseEmailTemplate(array $content): array
    {
        if (isset($content['type'])) {
            $content['templateId'] = $content['type'];
            unset($content['type']);
        }

        return $content;
    }

    public function parse(array $content, string $model): array
    {
        switch ($model) {
            case 'project.getEmailTemplate':
            case 'project.updateEmailTemplate':
            case 'project.deleteEmailTemplate':
                $content = $this->parseEmailTemplate($content);
                break;
        }
        return $content;
    }
}
