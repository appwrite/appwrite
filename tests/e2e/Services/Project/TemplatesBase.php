<?php

namespace Tests\E2E\Services\Project;

use Tests\E2E\Client;
use Utopia\Database\Query;

trait TemplatesBase
{
    // =========================================================================
    // Get email template tests
    // =========================================================================

    public function testGetEmailTemplateDefault(): void
    {
        $template = $this->getEmailTemplate('verification', 'en');

        $this->assertSame(200, $template['headers']['status-code']);
        $this->assertSame('verification', $template['body']['type']);
        $this->assertSame('en', $template['body']['locale']);
        $this->assertFalse($template['body']['custom']);
        $this->assertNotEmpty($template['body']['subject']);
        $this->assertNotEmpty($template['body']['message']);
    }

    public function testGetEmailTemplateDefaultLocale(): void
    {
        $template = $this->getEmailTemplate('verification');

        $this->assertSame(200, $template['headers']['status-code']);
        $this->assertSame('verification', $template['body']['type']);
        $this->assertSame('en', $template['body']['locale']);
        $this->assertFalse($template['body']['custom']);
    }

    public function testGetEmailTemplateCustom(): void
    {
        $update = $this->updateEmailTemplate('magicSession', 'en', 'Magic Subject', 'Magic Body');
        $this->assertSame(200, $update['headers']['status-code']);

        $get = $this->getEmailTemplate('magicSession', 'en');

        $this->assertSame(200, $get['headers']['status-code']);
        $this->assertSame('magicSession', $get['body']['type']);
        $this->assertSame('en', $get['body']['locale']);
        $this->assertTrue($get['body']['custom']);
        $this->assertSame('Magic Subject', $get['body']['subject']);
        $this->assertSame('Magic Body', $get['body']['message']);

        // Cleanup
        $this->deleteEmailTemplate('magicSession', 'en');
    }

    public function testGetEmailTemplateInvalidType(): void
    {
        $template = $this->getEmailTemplate('notATemplate', 'en');

        $this->assertSame(400, $template['headers']['status-code']);
    }

    public function testGetEmailTemplateInvalidLocale(): void
    {
        $template = $this->getEmailTemplate('verification', 'not-a-locale');

        $this->assertSame(400, $template['headers']['status-code']);
    }

    public function testGetEmailTemplateWithoutAuthentication(): void
    {
        $template = $this->getEmailTemplate('verification', 'en', false);

        $this->assertSame(401, $template['headers']['status-code']);
    }

    // =========================================================================
    // List email templates tests
    // =========================================================================

    public function testListEmailTemplates(): void
    {
        $list = $this->listEmailTemplates(null, true);

        $this->assertSame(200, $list['headers']['status-code']);
        $this->assertIsArray($list['body']['templates']);
        $this->assertGreaterThan(0, $list['body']['total']);
        $this->assertGreaterThan(0, \count($list['body']['templates']));

        foreach ($list['body']['templates'] as $template) {
            $this->assertArrayHasKey('type', $template);
            $this->assertArrayHasKey('locale', $template);
            $this->assertArrayHasKey('custom', $template);
            $this->assertArrayHasKey('subject', $template);
            $this->assertArrayHasKey('message', $template);
        }
    }

    public function testListEmailTemplatesWithLimit(): void
    {
        $list = $this->listEmailTemplates([
            Query::limit(5)->toString(),
        ], true);

        $this->assertSame(200, $list['headers']['status-code']);
        $this->assertCount(5, $list['body']['templates']);
        $this->assertGreaterThanOrEqual(5, $list['body']['total']);
    }

    public function testListEmailTemplatesWithOffset(): void
    {
        $first = $this->listEmailTemplates([
            Query::limit(2)->toString(),
        ], true);
        $this->assertSame(200, $first['headers']['status-code']);

        $second = $this->listEmailTemplates([
            Query::limit(2)->toString(),
            Query::offset(2)->toString(),
        ], true);
        $this->assertSame(200, $second['headers']['status-code']);

        $firstIds = \array_map(
            fn ($t) => $t['type'] . '-' . $t['locale'],
            $first['body']['templates']
        );
        $secondIds = \array_map(
            fn ($t) => $t['type'] . '-' . $t['locale'],
            $second['body']['templates']
        );

        $this->assertEmpty(\array_intersect($firstIds, $secondIds));
    }

    public function testListEmailTemplatesWithoutTotal(): void
    {
        $list = $this->listEmailTemplates(null, false);

        $this->assertSame(200, $list['headers']['status-code']);
        $this->assertSame(0, $list['body']['total']);
        $this->assertGreaterThan(0, \count($list['body']['templates']));
    }

    public function testListEmailTemplatesFilterByType(): void
    {
        $list = $this->listEmailTemplates([
            Query::equal('type', ['verification'])->toString(),
        ], true);

        $this->assertSame(200, $list['headers']['status-code']);
        $this->assertGreaterThan(0, $list['body']['total']);

        foreach ($list['body']['templates'] as $template) {
            $this->assertSame('verification', $template['type']);
        }
    }

    public function testListEmailTemplatesFilterByLocale(): void
    {
        $list = $this->listEmailTemplates([
            Query::equal('locale', ['en'])->toString(),
        ], true);

        $this->assertSame(200, $list['headers']['status-code']);
        $this->assertGreaterThan(0, $list['body']['total']);

        foreach ($list['body']['templates'] as $template) {
            $this->assertSame('en', $template['locale']);
        }
    }

    public function testListEmailTemplatesFilterByCustom(): void
    {
        $update = $this->updateEmailTemplate('recovery', 'en', 'Recovery Subject', 'Recovery Body');
        $this->assertSame(200, $update['headers']['status-code']);

        $list = $this->listEmailTemplates([
            Query::equal('custom', [true])->toString(),
            Query::limit(100)->toString(),
        ], true);

        $this->assertSame(200, $list['headers']['status-code']);
        $this->assertGreaterThanOrEqual(1, $list['body']['total']);

        $found = false;
        foreach ($list['body']['templates'] as $template) {
            $this->assertTrue($template['custom']);
            if ($template['type'] === 'recovery' && $template['locale'] === 'en') {
                $found = true;
            }
        }
        $this->assertTrue($found, 'Customized template should appear in custom=true filter');

        // Cleanup
        $this->deleteEmailTemplate('recovery', 'en');
    }

    public function testListEmailTemplatesCombinedFilters(): void
    {
        $list = $this->listEmailTemplates([
            Query::equal('type', ['verification'])->toString(),
            Query::equal('locale', ['en'])->toString(),
        ], true);

        $this->assertSame(200, $list['headers']['status-code']);
        $this->assertSame(1, $list['body']['total']);
        $this->assertCount(1, $list['body']['templates']);
        $this->assertSame('verification', $list['body']['templates'][0]['type']);
        $this->assertSame('en', $list['body']['templates'][0]['locale']);
    }

    public function testListEmailTemplatesDefaultMatchesGet(): void
    {
        $get = $this->getEmailTemplate('verification', 'en');
        $this->assertSame(200, $get['headers']['status-code']);
        $this->assertFalse($get['body']['custom']);

        $list = $this->listEmailTemplates([
            Query::equal('type', ['verification'])->toString(),
            Query::equal('locale', ['en'])->toString(),
        ], true);

        $this->assertSame(200, $list['headers']['status-code']);
        $this->assertCount(1, $list['body']['templates']);

        $listed = $list['body']['templates'][0];

        $this->assertSame($get['body']['type'], $listed['type']);
        $this->assertSame($get['body']['locale'], $listed['locale']);
        $this->assertSame($get['body']['custom'], $listed['custom']);
        $this->assertSame($get['body']['subject'], $listed['subject']);
        $this->assertSame($get['body']['message'], $listed['message']);
        $this->assertSame($get['body']['senderName'], $listed['senderName']);
        $this->assertSame($get['body']['senderEmail'], $listed['senderEmail']);
        $this->assertSame($get['body']['replyTo'], $listed['replyTo']);
    }

    public function testListEmailTemplatesOrderByType(): void
    {
        $asc = $this->listEmailTemplates([
            Query::orderAsc('type')->toString(),
            Query::limit(100)->toString(),
        ], true);
        $this->assertSame(200, $asc['headers']['status-code']);

        $ascTypes = \array_map(fn ($t) => $t['type'], $asc['body']['templates']);
        $sorted = $ascTypes;
        \sort($sorted);
        $this->assertSame($sorted, $ascTypes);

        $desc = $this->listEmailTemplates([
            Query::orderDesc('type')->toString(),
            Query::limit(100)->toString(),
        ], true);
        $this->assertSame(200, $desc['headers']['status-code']);

        $descTypes = \array_map(fn ($t) => $t['type'], $desc['body']['templates']);
        $sorted = $descTypes;
        \rsort($sorted);
        $this->assertSame($sorted, $descTypes);
    }

    public function testListEmailTemplatesInvalidQuery(): void
    {
        $list = $this->listEmailTemplates([
            Query::equal('notAnAttribute', ['foo'])->toString(),
        ], true);

        $this->assertSame(400, $list['headers']['status-code']);
    }

    public function testListEmailTemplatesWithoutAuthentication(): void
    {
        $list = $this->listEmailTemplates(null, true, false);

        $this->assertSame(401, $list['headers']['status-code']);
    }

    // =========================================================================
    // Update email template tests
    // =========================================================================

    public function testUpdateEmailTemplate(): void
    {
        $update = $this->updateEmailTemplate(
            'verification',
            'en',
            'Please verify your email',
            'Click here to verify: {{url}}',
        );

        $this->assertSame(200, $update['headers']['status-code']);
        $this->assertSame('verification', $update['body']['type']);
        $this->assertSame('en', $update['body']['locale']);
        $this->assertSame('Please verify your email', $update['body']['subject']);
        $this->assertSame('Click here to verify: {{url}}', $update['body']['message']);
        $this->assertTrue($update['body']['custom']);

        // Verify persisted via GET
        $get = $this->getEmailTemplate('verification', 'en');
        $this->assertSame(200, $get['headers']['status-code']);
        $this->assertSame('Please verify your email', $get['body']['subject']);
        $this->assertSame('Click here to verify: {{url}}', $get['body']['message']);
        $this->assertTrue($get['body']['custom']);

        // Cleanup
        $this->deleteEmailTemplate('verification', 'en');
    }

    public function testUpdateEmailTemplateWithOptionalFields(): void
    {
        $update = $this->updateEmailTemplate(
            'invitation',
            'en',
            'Team invitation',
            'You have been invited',
            'Appwrite Team',
            'team@appwrite.io',
            'reply@appwrite.io',
        );

        $this->assertSame(200, $update['headers']['status-code']);
        $this->assertSame('Team invitation', $update['body']['subject']);
        $this->assertSame('You have been invited', $update['body']['message']);
        $this->assertSame('Appwrite Team', $update['body']['senderName']);
        $this->assertSame('team@appwrite.io', $update['body']['senderEmail']);
        $this->assertSame('reply@appwrite.io', $update['body']['replyTo']);

        // Cleanup
        $this->deleteEmailTemplate('invitation', 'en');
    }

    public function testUpdateEmailTemplateDefaultLocale(): void
    {
        $update = $this->updateEmailTemplate(
            'sessionAlert',
            null,
            'Session alert',
            'Someone signed in',
        );

        $this->assertSame(200, $update['headers']['status-code']);
        $this->assertSame('sessionAlert', $update['body']['type']);
        $this->assertSame('en', $update['body']['locale']);

        // Cleanup
        $this->deleteEmailTemplate('sessionAlert', 'en');
    }

    public function testUpdateEmailTemplateOverwrite(): void
    {
        $this->updateEmailTemplate('otpSession', 'en', 'First', 'First body');

        $second = $this->updateEmailTemplate('otpSession', 'en', 'Second', 'Second body');

        $this->assertSame(200, $second['headers']['status-code']);
        $this->assertSame('Second', $second['body']['subject']);
        $this->assertSame('Second body', $second['body']['message']);

        $get = $this->getEmailTemplate('otpSession', 'en');
        $this->assertSame('Second', $get['body']['subject']);

        // Cleanup
        $this->deleteEmailTemplate('otpSession', 'en');
    }

    public function testUpdateEmailTemplateInvalidType(): void
    {
        $update = $this->updateEmailTemplate('notATemplate', 'en', 'Subject', 'Message');

        $this->assertSame(400, $update['headers']['status-code']);
    }

    public function testUpdateEmailTemplateMissingSubject(): void
    {
        $update = $this->updateEmailTemplate('verification', 'en', null, 'Message only');

        $this->assertSame(400, $update['headers']['status-code']);
    }

    public function testUpdateEmailTemplateMissingMessage(): void
    {
        $update = $this->updateEmailTemplate('verification', 'en', 'Subject only', null);

        $this->assertSame(400, $update['headers']['status-code']);
    }

    public function testUpdateEmailTemplateInvalidSenderEmail(): void
    {
        $update = $this->updateEmailTemplate(
            'verification',
            'en',
            'Subject',
            'Message',
            'Sender',
            'not-an-email',
        );

        $this->assertSame(400, $update['headers']['status-code']);
    }

    public function testUpdateEmailTemplateInvalidReplyTo(): void
    {
        $update = $this->updateEmailTemplate(
            'verification',
            'en',
            'Subject',
            'Message',
            null,
            null,
            'not-an-email',
        );

        $this->assertSame(400, $update['headers']['status-code']);
    }

    public function testUpdateEmailTemplateWithoutAuthentication(): void
    {
        $update = $this->updateEmailTemplate(
            'verification',
            'en',
            'Subject',
            'Message',
            null,
            null,
            null,
            false,
        );

        $this->assertSame(401, $update['headers']['status-code']);
    }

    // =========================================================================
    // Delete email template tests
    // =========================================================================

    public function testDeleteEmailTemplate(): void
    {
        $update = $this->updateEmailTemplate('mfaChallenge', 'en', 'MFA', 'Enter code');
        $this->assertSame(200, $update['headers']['status-code']);

        $customBefore = $this->getEmailTemplate('mfaChallenge', 'en');
        $this->assertTrue($customBefore['body']['custom']);

        $delete = $this->deleteEmailTemplate('mfaChallenge', 'en');
        $this->assertSame(204, $delete['headers']['status-code']);
        $this->assertEmpty($delete['body']);

        // Verify reset back to default
        $after = $this->getEmailTemplate('mfaChallenge', 'en');
        $this->assertSame(200, $after['headers']['status-code']);
        $this->assertFalse($after['body']['custom']);
        $this->assertNotSame('MFA', $after['body']['subject']);
    }

    public function testDeleteEmailTemplateDefault(): void
    {
        // Attempt to delete a template that was never customized
        $delete = $this->deleteEmailTemplate('verification', 'fr');

        $this->assertSame(401, $delete['headers']['status-code']);
        $this->assertSame('project_template_default_deletion', $delete['body']['type']);
    }

    public function testDeleteEmailTemplateInvalidType(): void
    {
        $delete = $this->deleteEmailTemplate('notATemplate', 'en');

        $this->assertSame(400, $delete['headers']['status-code']);
    }

    public function testDeleteEmailTemplateWithoutAuthentication(): void
    {
        $update = $this->updateEmailTemplate('recovery', 'en', 'Recovery', 'Reset password');
        $this->assertSame(200, $update['headers']['status-code']);

        $delete = $this->deleteEmailTemplate('recovery', 'en', false);

        $this->assertSame(401, $delete['headers']['status-code']);

        // Verify still customized
        $get = $this->getEmailTemplate('recovery', 'en');
        $this->assertTrue($get['body']['custom']);

        // Cleanup
        $this->deleteEmailTemplate('recovery', 'en');
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    protected function getEmailTemplate(string $type, ?string $locale = null, bool $authenticated = true): mixed
    {
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ];

        if ($authenticated) {
            $headers = \array_merge($headers, $this->getHeaders());
        }

        $params = [];
        if ($locale !== null) {
            $params['locale'] = $locale;
        }

        return $this->client->call(Client::METHOD_GET, '/project/templates/email/' . $type, $headers, $params);
    }

    /**
     * @param array<string>|null $queries
     */
    protected function listEmailTemplates(?array $queries, ?bool $total, bool $authenticated = true): mixed
    {
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ];

        if ($authenticated) {
            $headers = \array_merge($headers, $this->getHeaders());
        }

        $params = [];
        if ($queries !== null) {
            $params['queries'] = $queries;
        }
        if ($total !== null) {
            $params['total'] = $total;
        }

        return $this->client->call(Client::METHOD_GET, '/project/templates/email', $headers, $params);
    }

    protected function updateEmailTemplate(
        string $type,
        ?string $locale,
        ?string $subject,
        ?string $message,
        ?string $senderName = null,
        ?string $senderEmail = null,
        ?string $replyTo = null,
        bool $authenticated = true,
    ): mixed {
        $params = [
            'type' => $type,
        ];

        if ($locale !== null) {
            $params['locale'] = $locale;
        }
        if ($subject !== null) {
            $params['subject'] = $subject;
        }
        if ($message !== null) {
            $params['message'] = $message;
        }
        if ($senderName !== null) {
            $params['senderName'] = $senderName;
        }
        if ($senderEmail !== null) {
            $params['senderEmail'] = $senderEmail;
        }
        if ($replyTo !== null) {
            $params['replyTo'] = $replyTo;
        }

        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ];

        if ($authenticated) {
            $headers = \array_merge($headers, $this->getHeaders());
        }

        return $this->client->call(Client::METHOD_PATCH, '/project/templates/email', $headers, $params);
    }

    protected function deleteEmailTemplate(string $type, ?string $locale = null, bool $authenticated = true): mixed
    {
        $params = [
            'type' => $type,
        ];

        if ($locale !== null) {
            $params['locale'] = $locale;
        }

        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ];

        if ($authenticated) {
            $headers = \array_merge($headers, $this->getHeaders());
        }

        return $this->client->call(Client::METHOD_DELETE, '/project/templates/email', $headers, $params);
    }
}
