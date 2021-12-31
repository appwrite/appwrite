<?php

namespace Appwrite\Utopia\Request\Filters;

use Appwrite\Utopia\Request\Filter;

class V12 extends Filter
{
    // Convert 0.11 params format to 0.12 format
    public function parse(array $content, string $model): array
    {
        $parsedResponse = [];

        switch ($model) {
            // No IDs -> Custom IDs
            case "account.create":
            case "account.createMagicURLSession":
            case "users.create":
                $parsedResponse = $this->addId('userId', $content);
                break;
            case "functions.create":
                $parsedResponse = $this->addId('functionId', $content);
                break;
            case "teams.create":
                $parsedResponse = $this->addId('teamId', $content);
                break;

            // Status integer -> boolean
            case "users.updateStatus":
                $parsedResponse = $this->convertStatus($content);
                break;

            // The rest (more complex) formats
            case "database.createDocument":
                $parsedResponse = $this->addId('documentId', $content);
                break;
            case "database.createCollection":
                $parsedResponse = $this->addId('collectionId', $content);
                break;
        }

        if(empty($parsedResponse)) {
            // No changes between current version and the one user requested
            $parsedResponse = $content;
        }

        return $parsedResponse;
    }

    protected function addUserId(string $key, array $content): array
    {
        $content[$key] = 'unique()';
        return $content;
    }

    protected function convertStatus(array $content): array
    {
        $content['status'] = 'false'; // TODO: True or false. original is integer
        return $content;
    }
}
