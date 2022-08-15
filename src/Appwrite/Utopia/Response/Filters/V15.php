<?php

namespace Appwrite\Utopia\Response\Filters;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Filter;

class V15 extends Filter
{
    // Convert 0.16 Data format to 0.15 format
    public function parse(array $content, string $model): array
    {
        $parsedResponse = $content;

        switch ($model) {
            case Response::MODEL_SESSION:
            case Response::MODEL_TOKEN:
            case Response::MODEL_SESSION_LIST:
            case Response::MODEL_DOCUMENT:
            case Response::MODEL_DOMAIN:
            case Response::MODEL_FUNCTION:
            case Response::MODEL_TEAM:
            case Response::MODEL_MEMBERSHIP:
            case Response::MODEL_PLATFORM:
            case Response::MODEL_PROJECT:
            case Response::MODEL_USER:
            case Response::MODEL_WEBHOOK:
            case Response::MODEL_DOCUMENT_LIST:
            case Response::MODEL_DOMAIN_LIST:
            case Response::MODEL_FUNCTION_LIST:
            case Response::MODEL_TEAM_LIST:
            case Response::MODEL_MEMBERSHIP_LIST:
            case Response::MODEL_PLATFORM_LIST:
            case Response::MODEL_PROJECT_LIST:
            case Response::MODEL_USER_LIST:
            case Response::MODEL_WEBHOOK_LIST:
            case Response::MODEL_TEAM:
            case Response::MODEL_EXECUTION:
            case Response::MODEL_FILE:
            case Response::MODEL_TEAM_LIST:
            case Response::MODEL_EXECUTION_LIST:
            case Response::MODEL_FILE_LIST:
            case Response::MODEL_FUNCTION:
            case Response::MODEL_DEPLOYMENT:
            case Response::MODEL_BUCKET:
            case Response::MODEL_FUNCTION_LIST:
            case Response::MODEL_DEPLOYMENT_LIST:
            case Response::MODEL_BUCKET_LIST:
            case Response::MODEL_METRIC:
                $parsedResponse = $this->handleMetricAttributes($content);
        }

        return $parsedResponse;
    }

    protected function handleMetricAttributes(array $content) 
    {
        $content['timestamp'] = $content['date'];
        unset($content['date']);
    }

    protected function parseRemoveAttributes(array $content, array $attributes)
    {
        foreach ($attributes as $attribute) {
            unset($content[$attribute]);
        }

        return $content;
    }

    protected function parseRemoveAttributesList(array $content, string $property, array $attributes)
    {
        $documents = $content[$property];
        $parsedResponse = [];
        foreach ($documents as $document) {
            $parsedResponse[] = $this->parseRemoveAttributes($document, $attributes);
        }
        $content[$property] = $parsedResponse;

        return $content;
    }

    protected function parseCreatedAt(array $content)
    {
        $content['dateCreated'] = $content['$createdAt'];
        unset($content['$createdAt']);
        unset($content['$updatedAt']);

        return $content;
    }

    protected function parseCreatedAtList(array $content, string $property)
    {
        $documents = $content[$property];
        $parsedResponse = [];
        foreach ($documents as $document) {
            $parsedResponse[] = $this->parseCreatedAt($document);
        }
        $content[$property] = $parsedResponse;

        return $content;
    }

    protected function parseCreatedAtAndUpdatedAt(array $content)
    {
        $content['dateCreated'] = $content['$createdAt'];
        $content['dateUpdated'] = $content['$updatedAt'];
        unset($content['$createdAt']);
        unset($content['$updatedAt']);

        return $content;
    }

    protected function parseCreatedAtAndUpdatedAtList(array $content, string $property)
    {
        $documents = $content[$property];
        $parsedResponse = [];
        foreach ($documents as $document) {
            $parsedResponse[] = $this->parseCreatedAtAndUpdatedAt($document);
        }
        $content[$property] = $parsedResponse;

        return $content;
    }
}
