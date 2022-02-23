<?php

namespace Appwrite\Utopia\Response\Filters;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Filter;
use Exception;

class V12 extends Filter
{
    // Convert 0.13 Data format to 0.12 format
    public function parse(array $content, string $model): array
    {
        $parsedResponse = $content;

        switch ($model) {
            // Update permissions
            case Response::MODEL_ERROR:
                $parsedResponse = $this->parseError($content);
                break;
            case Response::MODEL_SESSION:
                $parsedResponse = $this->parseSession($content);

            case Response::MODEL_FILE:
                $parsedResponse = $this->parseFile($content);

            case Response::MODEL_FUNCTION:
                $parsedResponse = $this->parseFunction($content);

            case Response::MODEL_USAGE_BUCKETS:
                $parsedResponse = $this->parseUsageBuckets($content);

            case Response::MODEL_USAGE_STORAGE:
                $parsedResponse = $this->parseUsageStorage($content);

        }

        return $parsedResponse;
    }

    protected function parseError(array $content)
    {
        unset($content['type']);
        return $content;
    }

    protected function parseSession(array $content)
    {
        $content['providerToken'] = $content['providerAccessToken'];
        unset($content['providerAccessToken']);

        unset($content['providerAccessTokenExpiry']);

        unset($content['providerRefreshToken']);

        return $content;
    }

    protected function parseFile(array $content)
    {
        unset($content['bucketId']);
        unset($content['chunksTotal']);
        unset($content['chunksUploaded']);

        return $content;
    }

    protected function parseFunction(array $content)
    {
        $content['execute'] = implode(' ', $content['execute']);

        return $content;
    }

    protected function parseUsageBuckets(array $content)
    {
        unset($content['filesStorage']);
    }

    protected function parseUsageStorage(array $content)
    {
        $content['storage'] = $content['filesStorage'];
        unset($content['filesStorage']);

        $content['files'] = $content['tagsStorage'];
        unset($content['tagsStorage']);

        unset($content['filesCount']);
        unset($content['bucketsCount']);
        unset($content['bucketsCreate']);
        unset($content['bucketsRead']);
        unset($content['bucketsUpdate']);
        unset($content['bucketsDelete']);
        unset($content['filesCount']);
        unset($content['bucketsDelete']);
        unset($content['filesCreate']);
        unset($content['filesRead']);
        unset($content['filesUpdate']);
        unset($content['filesDelete']);
    }
}