<?php

namespace Appwrite\Utopia\Request\Filters;

use Appwrite\Utopia\Request\Filter;

class V23 extends Filter
{
    // Convert 1.9.1 params to 1.9.2
    public function parse(array $content, string $model): array
    {
        switch ($model) {
            case 'project.getEmailTemplate':
            case 'project.deleteEmailTemplate':
                $content = $this->parseEmailTemplate($content);
                break;
            case 'project.updateEmailTemplate':
                $content = $this->parseEmailTemplate($content);
                $content = $this->parseReplyTo($content);
                break;
            case 'project.updateSMTP':
                $content = $this->parseReplyTo($content);
                break;
            case 'project.updateMembershipPrivacyPolicy':
                $content = $this->parseUpdateMembershipPrivacyPolicy($content);
                break;
            case 'project.updateSessionAlertPolicy':
                $content = $this->parseUpdateSessionAlertPolicy($content);
                break;
            case 'project.updateUserLimitPolicy':
            case 'project.updatePasswordHistoryPolicy':
            case 'project.updateSessionLimitPolicy':
                $content = $this->parseLimitToTotal($content);
                break;
            case 'project.updateAuthMethod':
                $content = $this->parseUpdateAuthMethod($content);
                break;
        }

        return $content;
    }

    protected function parseUpdateMembershipPrivacyPolicy(array $content): array
    {
        $content['userId'] = false;
        $content['userPhone'] = false;

        if (isset($content['mfa'])) {
            $content['userMFA'] = $content['mfa'];
            unset($content['mfa']);
        }

        return $content;
    }

    protected function parseUpdateSessionAlertPolicy(array $content): array
    {
        if (isset($content['alerts'])) {
            $content['enabled'] = $content['alerts'];
            unset($content['alerts']);
        }

        return $content;
    }

    protected function parseUpdateAuthMethod(array $content): array
    {
        if (isset($content['status'])) {
            $content['enabled'] = $content['status'];
            unset($content['status']);
        }

        if (isset($content['method'])) {
            $content['methodId'] = $content['method'];
            unset($content['method']);
        }

        return $content;
    }

    protected function parseLimitToTotal(array $content): array
    {
        if (isset($content['limit'])) {
            $content['total'] = $content['limit'] === 0 ? null : $content['limit'];
            unset($content['limit']);
        }

        return $content;
    }

    protected function parseEmailTemplate(array $content): array
    {
        if (isset($content['type'])) {
            $content['templateId'] = $content['type'];
            unset($content['type']);
        }

        return $content;
    }

    protected function parseReplyTo(array $content): array
    {
        if (isset($content['replyTo'])) {
            $content['replyToEmail'] = $content['replyTo'];
            unset($content['replyTo']);
        }

        return $content;
    }
}
