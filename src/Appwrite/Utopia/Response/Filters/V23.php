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
            Response::MODEL_MEMBERSHIP => $this->parseMembership($content),
            Response::MODEL_MEMBERSHIP_LIST => $this->handleList($content, 'memberships', fn ($item) => $this->parseMembership($item)),
            Response::MODEL_PROJECT => $this->parseProject($content),
            Response::MODEL_PROJECT_LIST => $this->handleList($content, 'projects', fn ($item) => $this->parseProject($item)),
            Response::MODEL_EMAIL_TEMPLATE => $this->parseEmailTemplate($content),
            default => $content,
        };
    }

    private function parseMembership(array $content): array
    {
        unset($content['userPhone']);

        return $content;
    }

    private function parseEmailTemplate(array $content): array
    {
        if (isset($content['templateId'])) {
            $content['type'] = $content['templateId'];
            unset($content['templateId']);
        }

        if (isset($content['replyToEmail'])) {
            $content['replyTo'] = $content['replyToEmail'];
            unset($content['replyToEmail']);
        }

        unset($content['replyToName']);
        unset($content['custom']);

        return $content;
    }

    private function parseProject(array $content): array
    {
        unset($content['authMembershipsUserId']);
        unset($content['authMembershipsUserPhone']);

        if (isset($content['smtpReplyToEmail'])) {
            $content['smtpReplyTo'] = $content['smtpReplyToEmail'];
            unset($content['smtpReplyToEmail']);
        }

        unset($content['smtpReplyToName']);

        return $content;
    }
}
