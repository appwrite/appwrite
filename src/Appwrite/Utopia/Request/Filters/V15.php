<?php

namespace Appwrite\Utopia\Request\Filters;

use Appwrite\Utopia\Request\Filter;
use Utopia\Database\Query;

class V15 extends Filter
{
    // Convert 0.15 params format to 0.16 format
    public function parse(array $content, string $model): array
    {
        switch ($model) {
            // Old Query -> New Query
            case  "account.logs":
                $content = $this->handleAccountLogs($content);
                break;
            case "account.initials":
                $content = $this->handleInitials($content);
        }

        return $content;
    }

    protected function handleAccountLogs($content)
    {
        // Translate Old Query System to New Query System

        if (!empty($content['limit'])) {
            $content['queries'][] = 'Query.limit('.$content['limit'].')';
        } 

        if (!empty($content['offset'])) {
            $content['queries'][] = 'Query.offset('.$content['offset'].')';
        }

        unset($content['limit']);
        unset($content['offset']);

        return $content;
    }

    protected function handleInitials($content)
    {
        unset($content[' color']);

        return $content;
    }

    protected function handleQueryTranslation($content) {
        $content['queries'] = [];

        if (isset($content['limit'])) {
            $content['queries'][] = Query::limit($content['limit']);
        } 

        if (isset($content['offset'])) {
            $content['queries'][] = Query::offset($content['offset']);
        }

        if (isset($content['cursor'])) {
            $direction = $content['cursorDirection'] ?? 'after';
            
            if ($direction === 'after') {
                $content['queries'][] = Query::cursorAfter($content['cursor']);
            } else {
                $content['queries'][] = Query::cursorBefore($content['cursor']);
            }
        }

        if (isset($content['orderAttributes'])) {
            foreach ($content['orderAttributes'] as $i=>$attribute) {
                if ($content['orderTypes'][$i] === 'ASC') {
                    $content['queries'][] = Query::orderAsc($attribute);
                } else if ($content['orderTypes'][$i] === 'DESC') {
                    $content['queries'][] = Query::orderDesc($attribute);
                } else {
                    continue;
                }
            }
        }
        
        unset($content['limit']);
        unset($content['offset']);
        unset($content['cursor']);
        unset($content['orderAttributes']);
        unset($content['orderTypes']);

        return $content;
    }
}
