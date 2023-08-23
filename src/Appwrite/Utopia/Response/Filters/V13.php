<?php

namespace Appwrite\Utopia\Response\Filters;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Filter;

class V13 extends Filter
{
    // Convert 0.14 Data format to 0.13 format
    public function parse(array $content, string $model): array
    {
        $parsedResponse = $content;

        switch ($model) {
            case Response::MODEL_PROJECT:
                $parsedResponse = $this->parseProject($content);
                break;

            case Response::MODEL_PROJECT_LIST:
                $parsedResponse = $this->parseProjectList($content);
                break;

            case Response::MODEL_MEMBERSHIP:
                $parsedResponse = $this->parseMembership($content);
                break;
            case Response::MODEL_MEMBERSHIP_LIST:
                $parsedResponse = $this->parseMembershipList($content);
                break;

            case Response::MODEL_EXECUTION:
                $parsedResponse = $this->parseExecution($content);
                break;
            case Response::MODEL_EXECUTION_LIST:
                $parsedResponse = $this->parseExecutionList($content);
                break;
        }

        return $parsedResponse;
    }

    protected function parseExecution($content)
    {
        $content['stdout'] = $content['response'];
        unset($content['response']);

        return $content;
    }

    protected function parseExecutionList($content)
    {
        $executions = $content['executions'];
        $parsedResponse = [];
        foreach ($executions as $document) {
            $parsedResponse[] = $this->parseExecution($document);
        }
        $content['executions'] = $parsedResponse;

        return $content;
    }

    protected function parseProject($content)
    {
        $content['providers'] = $content['authProviders'];
        unset($content['authProviders']);

        return $content;
    }

    protected function parseProjectList($content)
    {
        $projects = $content['projects'];
        $parsedResponse = [];
        foreach ($projects as $document) {
            $parsedResponse[] = $this->parseProject($document);
        }
        $content['projects'] = $parsedResponse;

        return $content;
    }

    protected function parseMembership($content)
    {
        $content['name'] = $content['userName'];
        unset($content['userName']);

        $content['email'] = $content['userEmail'];
        unset($content['userEmail']);

        unset($content['teamName']);

        return $content;
    }

    protected function parseMembershipList($content)
    {
        $memberships = $content['memberships'];
        $parsedResponse = [];
        foreach ($memberships as $document) {
            $parsedResponse[] = $this->parseMembership($document);
        }
        $content['memberships'] = $parsedResponse;

        return $content;
    }
}
