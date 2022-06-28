<?php

namespace Appwrite\Utopia\Response\Filters;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Filter;

class V14 extends Filter
{
    // Convert 0.15 Data format to 0.14 format
    public function parse(array $content, string $model): array
    {
        $parsedResponse = $content;

        switch ($model) {
            case Response::MODEL_SESSION:
            case Response::MODEL_TOKEN:
                $parsedResponse = $this->parseRemoveAttributes($content, ['$createdAt']);

                break;
            case Response::MODEL_SESSION_LIST:
                $parsedResponse = $this->parseRemoveAttributesList($content, 'domains', ['$createdAt']);

                break;
            case Response::MODEL_DOCUMENT:
            case Response::MODEL_DOMAIN:
            case Response::MODEL_FUNCTION:
            case Response::MODEL_TEAM:
            case Response::MODEL_MEMBERSHIP:
            case Response::MODEL_PLATFORM:
            case Response::MODEL_PROJECT:
            case Response::MODEL_USER:
            case Response::MODEL_WEBHOOK:
                $parsedResponse = $this->parseRemoveAttributes($content, ['$createdAt', '$updatedAt']);

                break;
            case Response::MODEL_DOCUMENT_LIST:
                $parsedResponse = $this->parseRemoveAttributesList($content, 'documents', ['$createdAt', '$updatedAt']);

                break;
            case Response::MODEL_DOMAIN_LIST:
                $parsedResponse = $this->parseRemoveAttributesList($content, 'domains', ['$createdAt', '$updatedAt']);

                break;
            case Response::MODEL_FUNCTION_LIST:
                $parsedResponse = $this->parseRemoveAttributesList($content, 'functions', ['$createdAt', '$updatedAt']);

                break;
            case Response::MODEL_TEAM_LIST:
                $parsedResponse = $this->parseRemoveAttributesList($content, 'teams', ['$createdAt', '$updatedAt']);

                break;
            case Response::MODEL_MEMBERSHIP_LIST:
                $parsedResponse = $this->parseRemoveAttributesList($content, 'memberships', ['$createdAt', '$updatedAt']);

                break;
            case Response::MODEL_PLATFORM_LIST:
                $parsedResponse = $this->parseRemoveAttributesList($content, 'platforms', ['$createdAt', '$updatedAt']);

                break;
            case Response::MODEL_PROJECT_LIST:
                $parsedResponse = $this->parseRemoveAttributesList($content, 'projects', ['$createdAt', '$updatedAt']);

                break;
            case Response::MODEL_USER_LIST:
                $parsedResponse = $this->parseRemoveAttributesList($content, 'users', ['$createdAt', '$updatedAt']);

                break;
            case Response::MODEL_WEBHOOK_LIST:
                $parsedResponse = $this->parseRemoveAttributesList($content, 'webhooks', ['$createdAt', '$updatedAt']);

                break;
            case Response::MODEL_TEAM:
            case Response::MODEL_EXECUTION:
            case Response::MODEL_FILE:
                $parsedResponse = $this->parseCreatedAt($content);
                break;

            case Response::MODEL_TEAM_LIST:
                $parsedResponse = $this->parseCreatedAtList($content, 'teams');
                break;

            case Response::MODEL_EXECUTION_LIST:
                $parsedResponse = $this->parseCreatedAtList($content, 'executions');
                break;

            case Response::MODEL_FILE_LIST:
                $parsedResponse = $this->parseCreatedAtList($content, 'files');
                break;

            case Response::MODEL_FUNCTION:
            case Response::MODEL_DEPLOYMENT:
            case Response::MODEL_BUCKET:
                $parsedResponse = $this->parseCreatedAtAndUpdatedAt($content);
                break;

            case Response::MODEL_FUNCTION_LIST:
                $parsedResponse = $this->parseCreatedAtAndUpdatedAtList($content, 'functions');
                break;

            case Response::MODEL_DEPLOYMENT_LIST:
                $parsedResponse = $this->parseCreatedAtAndUpdatedAtList($content, 'deployments');
                break;

            case Response::MODEL_BUCKET_LIST:
                $parsedResponse = $this->parseCreatedAtAndUpdatedAtList($content, 'buckets');
                break;
        }

        return $parsedResponse;
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
