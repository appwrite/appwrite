<?php

namespace Tests\E2E\Services\Project;

use Tests\E2E\Client;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;

trait TemplatesBase
{
    // Get email template tests

    public function testGetEmailTemplateDefault(): void
    {
        $response = $this->getEmailTemplate('verification', 'en');

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('verification', $response['body']['templateId']);
        $this->assertSame('en', $response['body']['locale']);
        $this->assertNotEmpty($response['body']['subject']);
        $this->assertNotEmpty($response['body']['message']);
    }

    public function testGetEmailTemplateDefaultLocale(): void
    {
        // When locale is omitted, the fallback locale (en) is applied server-side.
        $response = $this->getEmailTemplate('recovery');

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('recovery', $response['body']['templateId']);
        $this->assertSame('en', $response['body']['locale']);
        $this->assertNotEmpty($response['body']['subject']);
        $this->assertNotEmpty($response['body']['message']);
    }

    public function testGetEmailTemplateAllSupportedTypes(): void
    {
        $types = [
            'verification',
            'magicSession',
            'recovery',
            'invitation',
            'mfaChallenge',
            'sessionAlert',
            'otpSession',
        ];

        foreach ($types as $type) {
            $response = $this->getEmailTemplate($type, 'en');

            $this->assertSame(200, $response['headers']['status-code'], "type={$type}");
            $this->assertSame($type, $response['body']['templateId']);
            $this->assertSame('en', $response['body']['locale']);
            $this->assertNotEmpty($response['body']['subject'], "type={$type} must have default subject");
            $this->assertNotEmpty($response['body']['message'], "type={$type} must have default message");
        }
    }

    public function testGetEmailTemplateNonDefaultLocale(): void
    {
        // Even a non-en locale that has no custom template must return defaults.
        $response = $this->getEmailTemplate('verification', 'fr');

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('verification', $response['body']['templateId']);
        $this->assertSame('fr', $response['body']['locale']);
        $this->assertNotEmpty($response['body']['subject']);
        $this->assertNotEmpty($response['body']['message']);
    }

    public function testGetEmailTemplateResponseModel(): void
    {
        $response = $this->getEmailTemplate('verification', 'en');

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertArrayHasKey('templateId', $response['body']);
        $this->assertArrayHasKey('locale', $response['body']);
        $this->assertArrayHasKey('subject', $response['body']);
        $this->assertArrayHasKey('message', $response['body']);
        $this->assertArrayHasKey('senderName', $response['body']);
        $this->assertArrayHasKey('senderEmail', $response['body']);
        $this->assertArrayHasKey('replyToEmail', $response['body']);
        $this->assertArrayHasKey('replyToName', $response['body']);
    }

    public function testGetEmailTemplateInvalidType(): void
    {
        $response = $this->getEmailTemplate('notATemplate', 'en');

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testGetEmailTemplateInvalidLocale(): void
    {
        $response = $this->getEmailTemplate('verification', 'not-a-locale');

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testGetEmailTemplateWithoutAuthentication(): void
    {
        $response = $this->getEmailTemplate('verification', 'en', false);

        $this->assertSame(401, $response['headers']['status-code']);
    }

    public function testGetEmailTemplateReturnsCustomValues(): void
    {
        $this->ensureSMTPEnabled();

        $subject = 'Custom invitation subject ' . \uniqid();
        $message = 'Custom invitation body ' . \uniqid();

        $update = $this->updateEmailTemplate(
            templateId: 'invitation',
            locale: 'en',
            subject: $subject,
            message: $message,
            senderName: 'Invitation Sender',
            senderEmail: 'invitation@appwrite.io',
            replyToEmail: 'reply-invitation@appwrite.io',
            replyToName: 'Invitation Reply',
        );
        $this->assertSame(200, $update['headers']['status-code']);

        $get = $this->getEmailTemplate('invitation', 'en');

        $this->assertSame(200, $get['headers']['status-code']);
        $this->assertSame('invitation', $get['body']['templateId']);
        $this->assertSame('en', $get['body']['locale']);
        $this->assertSame($subject, $get['body']['subject']);
        $this->assertSame($message, $get['body']['message']);
        $this->assertSame('Invitation Sender', $get['body']['senderName']);
        $this->assertSame('invitation@appwrite.io', $get['body']['senderEmail']);
        $this->assertSame('reply-invitation@appwrite.io', $get['body']['replyToEmail']);
        $this->assertSame('Invitation Reply', $get['body']['replyToName']);
    }

    public function testGetEmailTemplateCustomizationIsLocaleScoped(): void
    {
        $this->ensureSMTPEnabled();

        $enSubject = 'EN only subject ' . \uniqid();
        $update = $this->updateEmailTemplate(
            templateId: 'mfaChallenge',
            locale: 'en',
            subject: $enSubject,
            message: 'EN only message',
        );
        $this->assertSame(200, $update['headers']['status-code']);

        // Another locale must still return its defaults — not the en customization.
        $other = $this->getEmailTemplate('mfaChallenge', 'de');
        $this->assertSame(200, $other['headers']['status-code']);
        $this->assertSame('de', $other['body']['locale']);
        $this->assertNotSame($enSubject, $other['body']['subject']);
    }

    // Update email template tests

    public function testUpdateEmailTemplateRequiredFields(): void
    {
        $this->ensureSMTPEnabled();

        $response = $this->updateEmailTemplate(
            templateId: 'verification',
            locale: 'en',
            subject: 'Please verify your email',
            message: 'Click here to verify: {{url}}',
        );

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('verification', $response['body']['templateId']);
        $this->assertSame('en', $response['body']['locale']);
        $this->assertSame('Please verify your email', $response['body']['subject']);
        $this->assertSame('Click here to verify: {{url}}', $response['body']['message']);
    }

    public function testUpdateEmailTemplateAllFields(): void
    {
        $this->ensureSMTPEnabled();

        $response = $this->updateEmailTemplate(
            templateId: 'recovery',
            locale: 'en',
            subject: 'Password reset',
            message: 'Reset your password',
            senderName: 'Security Team',
            senderEmail: 'security@appwrite.io',
            replyToEmail: 'noreply@appwrite.io',
            replyToName: 'No Reply',
        );

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('Password reset', $response['body']['subject']);
        $this->assertSame('Reset your password', $response['body']['message']);
        $this->assertSame('Security Team', $response['body']['senderName']);
        $this->assertSame('security@appwrite.io', $response['body']['senderEmail']);
        $this->assertSame('noreply@appwrite.io', $response['body']['replyToEmail']);
        $this->assertSame('No Reply', $response['body']['replyToName']);
    }

    public function testUpdateEmailTemplateDefaultLocale(): void
    {
        $this->ensureSMTPEnabled();

        // Omit locale entirely; server falls back to `en`.
        $response = $this->updateEmailTemplate(
            templateId: 'sessionAlert',
            locale: null,
            subject: 'Session alert',
            message: 'Someone signed in',
        );

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('sessionAlert', $response['body']['templateId']);
        $this->assertSame('en', $response['body']['locale']);
    }

    public function testUpdateEmailTemplateOverwritesPrevious(): void
    {
        $this->ensureSMTPEnabled();

        $first = $this->updateEmailTemplate(
            templateId: 'otpSession',
            locale: 'en',
            subject: 'First subject',
            message: 'First body',
        );
        $this->assertSame(200, $first['headers']['status-code']);

        $second = $this->updateEmailTemplate(
            templateId: 'otpSession',
            locale: 'en',
            subject: 'Second subject',
            message: 'Second body',
        );
        $this->assertSame(200, $second['headers']['status-code']);
        $this->assertSame('Second subject', $second['body']['subject']);
        $this->assertSame('Second body', $second['body']['message']);

        $get = $this->getEmailTemplate('otpSession', 'en');
        $this->assertSame('Second subject', $get['body']['subject']);
        $this->assertSame('Second body', $get['body']['message']);
    }

    public function testUpdateEmailTemplatePartialAfterSeed(): void
    {
        $this->ensureSMTPEnabled();

        // Seed a fully configured template.
        $seed = $this->updateEmailTemplate(
            templateId: 'magicSession',
            locale: 'en',
            subject: 'Magic subject',
            message: 'Magic body',
            senderName: 'Magic Sender',
            senderEmail: 'magic@appwrite.io',
            replyToEmail: 'magic-reply@appwrite.io',
            replyToName: 'Magic Reply',
        );
        $this->assertSame(200, $seed['headers']['status-code']);

        // Once seeded, sending just one field is fine: previous subject/message persist.
        $response = $this->updateEmailTemplate(
            templateId: 'magicSession',
            locale: 'en',
            senderName: 'Updated Sender',
        );

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('Updated Sender', $response['body']['senderName']);
        $this->assertSame('Magic subject', $response['body']['subject']);
        $this->assertSame('Magic body', $response['body']['message']);
        $this->assertSame('magic@appwrite.io', $response['body']['senderEmail']);
        $this->assertSame('magic-reply@appwrite.io', $response['body']['replyToEmail']);
        $this->assertSame('Magic Reply', $response['body']['replyToName']);
    }

    public function testUpdateEmailTemplateDifferentLocales(): void
    {
        $this->ensureSMTPEnabled();

        $enUpdate = $this->updateEmailTemplate(
            templateId: 'invitation',
            locale: 'en',
            subject: 'English subject',
            message: 'English body',
        );
        $this->assertSame(200, $enUpdate['headers']['status-code']);
        $this->assertSame('en', $enUpdate['body']['locale']);
        $this->assertSame('English subject', $enUpdate['body']['subject']);

        $frUpdate = $this->updateEmailTemplate(
            templateId: 'invitation',
            locale: 'fr',
            subject: 'Sujet francais',
            message: 'Corps francais',
        );
        $this->assertSame(200, $frUpdate['headers']['status-code']);
        $this->assertSame('fr', $frUpdate['body']['locale']);
        $this->assertSame('Sujet francais', $frUpdate['body']['subject']);

        // Locales remain independent.
        $enGet = $this->getEmailTemplate('invitation', 'en');
        $this->assertSame('English subject', $enGet['body']['subject']);

        $frGet = $this->getEmailTemplate('invitation', 'fr');
        $this->assertSame('Sujet francais', $frGet['body']['subject']);
    }

    public function testUpdateEmailTemplateResponseModel(): void
    {
        $this->ensureSMTPEnabled();

        $response = $this->updateEmailTemplate(
            templateId: 'verification',
            locale: 'en',
            subject: 'Model check subject',
            message: 'Model check body',
            senderName: 'Sender',
            senderEmail: 'sender@appwrite.io',
            replyToEmail: 'reply@appwrite.io',
            replyToName: 'Reply',
        );

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertArrayHasKey('templateId', $response['body']);
        $this->assertArrayHasKey('locale', $response['body']);
        $this->assertArrayHasKey('subject', $response['body']);
        $this->assertArrayHasKey('message', $response['body']);
        $this->assertArrayHasKey('senderName', $response['body']);
        $this->assertArrayHasKey('senderEmail', $response['body']);
        $this->assertArrayHasKey('replyToEmail', $response['body']);
        $this->assertArrayHasKey('replyToName', $response['body']);
    }

    public function testUpdateEmailTemplateSubjectMaxLength(): void
    {
        $this->ensureSMTPEnabled();

        $subject = \str_repeat('a', 255);
        $response = $this->updateEmailTemplate(
            templateId: 'verification',
            locale: 'en',
            subject: $subject,
            message: 'Body',
        );

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame($subject, $response['body']['subject']);
    }

    public function testUpdateEmailTemplateSubjectTooLong(): void
    {
        $this->ensureSMTPEnabled();

        $response = $this->updateEmailTemplate(
            templateId: 'verification',
            locale: 'en',
            subject: \str_repeat('a', 256),
            message: 'Body',
        );

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testUpdateEmailTemplateSenderNameEmptyAllowed(): void
    {
        $this->ensureSMTPEnabled();

        // senderName validator explicitly allows empty strings (Text(255, 0)).
        $response = $this->updateEmailTemplate(
            templateId: 'verification',
            locale: 'en',
            subject: 'Subject',
            message: 'Message',
            senderName: '',
        );

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('', $response['body']['senderName']);
    }

    public function testUpdateEmailTemplateReplyToNameEmptyAllowed(): void
    {
        $this->ensureSMTPEnabled();

        // replyToName validator explicitly allows empty strings (Text(255, 0)).
        $response = $this->updateEmailTemplate(
            templateId: 'verification',
            locale: 'en',
            subject: 'Subject',
            message: 'Message',
            replyToName: '',
        );

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('', $response['body']['replyToName']);
    }

    public function testUpdateEmailTemplateSenderNameTooLong(): void
    {
        $this->ensureSMTPEnabled();

        $response = $this->updateEmailTemplate(
            templateId: 'verification',
            locale: 'en',
            subject: 'Subject',
            message: 'Message',
            senderName: \str_repeat('a', 256),
        );

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testUpdateEmailTemplateInvalidType(): void
    {
        $this->ensureSMTPEnabled();

        $response = $this->updateEmailTemplate(
            templateId: 'notATemplate',
            locale: 'en',
            subject: 'Subject',
            message: 'Message',
        );

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testUpdateEmailTemplateInvalidLocale(): void
    {
        $this->ensureSMTPEnabled();

        $response = $this->updateEmailTemplate(
            templateId: 'verification',
            locale: 'not-a-locale',
            subject: 'Subject',
            message: 'Message',
        );

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testUpdateEmailTemplateMissingSubjectOnFirstWrite(): void
    {
        $this->ensureSMTPEnabled();

        // 'recovery'/'de' was never customized, so there is no persisted subject
        // to fall back on — the endpoint must reject the request.
        $response = $this->updateEmailTemplate(
            templateId: 'recovery',
            locale: 'de',
            subject: null,
            message: 'Body only',
        );

        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $response['body']['type']);
    }

    public function testUpdateEmailTemplateMissingMessageOnFirstWrite(): void
    {
        $this->ensureSMTPEnabled();

        // 'invitation'/'es' was never customized, so there is no persisted message
        // to fall back on — the endpoint must reject the request.
        $response = $this->updateEmailTemplate(
            templateId: 'invitation',
            locale: 'es',
            subject: 'Subject only',
            message: null,
        );

        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $response['body']['type']);
    }

    public function testUpdateEmailTemplateEmptySubject(): void
    {
        $this->ensureSMTPEnabled();

        // Text(255) validator requires min length 1 — empty subject is rejected.
        $response = $this->updateEmailTemplate(
            templateId: 'verification',
            locale: 'en',
            subject: '',
            message: 'Body',
        );

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testUpdateEmailTemplateEmptyMessage(): void
    {
        $this->ensureSMTPEnabled();

        $response = $this->updateEmailTemplate(
            templateId: 'verification',
            locale: 'en',
            subject: 'Subject',
            message: '',
        );

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testUpdateEmailTemplateInvalidSenderEmail(): void
    {
        $this->ensureSMTPEnabled();

        $response = $this->updateEmailTemplate(
            templateId: 'verification',
            locale: 'en',
            subject: 'Subject',
            message: 'Message',
            senderEmail: 'not-an-email',
        );

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testUpdateEmailTemplateInvalidReplyToEmail(): void
    {
        $this->ensureSMTPEnabled();

        $response = $this->updateEmailTemplate(
            templateId: 'verification',
            locale: 'en',
            subject: 'Subject',
            message: 'Message',
            replyToEmail: 'not-an-email',
        );

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testUpdateEmailTemplateWithoutAuthentication(): void
    {
        $response = $this->updateEmailTemplate(
            templateId: 'verification',
            locale: 'en',
            subject: 'Subject',
            message: 'Message',
            authenticated: false,
        );

        $this->assertSame(401, $response['headers']['status-code']);
    }

    public function testUpdateEmailTemplateBlockedWhenSMTPDisabled(): void
    {
        // Custom templates only make sense alongside a custom SMTP configuration.
        $response = $this->client->call(
            Client::METHOD_PATCH,
            '/project/smtp',
            \array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()),
            ['enabled' => false],
        );

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(false, $response['body']['smtpEnabled']);

        try {
            $response = $this->updateEmailTemplate(
                templateId: 'verification',
                locale: 'en',
                subject: 'Should be blocked',
                message: 'Should be blocked',
            );

            $this->assertSame(400, $response['headers']['status-code']);
            $this->assertSame('general_argument_invalid', $response['body']['type']);
            $this->assertStringContainsStringIgnoringCase('SMTP', $response['body']['message']);
        } finally {
            $this->ensureSMTPEnabled();
        }
    }

    // List email template tests

    public function testListEmailTemplatesReturnsSeededTemplate(): void
    {
        $this->ensureSMTPEnabled();

        $subject = 'List subject ' . \uniqid();
        $seed = $this->updateEmailTemplate(
            templateId: 'verification',
            locale: 'en',
            subject: $subject,
            message: 'List body',
        );
        $this->assertSame(200, $seed['headers']['status-code']);

        $response = $this->listEmailTemplates();

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertArrayHasKey('templates', $response['body']);
        $this->assertArrayHasKey('total', $response['body']);
        $this->assertIsArray($response['body']['templates']);
        $this->assertIsInt($response['body']['total']);
        $this->assertGreaterThanOrEqual(1, $response['body']['total']);

        $found = null;
        foreach ($response['body']['templates'] as $template) {
            if (
                $template['templateId'] === 'verification'
                && $template['locale'] === 'en'
                && $template['subject'] === $subject
            ) {
                $found = $template;
                break;
            }
        }
        $this->assertNotNull($found, 'seeded verification/en template must appear in the list');
    }

    public function testListEmailTemplatesResponseModel(): void
    {
        $this->ensureSMTPEnabled();

        $seed = $this->updateEmailTemplate(
            templateId: 'invitation',
            locale: 'en',
            subject: 'Shape subject ' . \uniqid(),
            message: 'Shape body',
            senderName: 'Shape Sender',
            senderEmail: 'shape@appwrite.io',
            replyToEmail: 'shape-reply@appwrite.io',
            replyToName: 'Shape Reply',
        );
        $this->assertSame(200, $seed['headers']['status-code']);

        $response = $this->listEmailTemplates();

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['templates']);

        foreach ($response['body']['templates'] as $template) {
            $this->assertArrayHasKey('templateId', $template);
            $this->assertArrayHasKey('locale', $template);
            $this->assertArrayHasKey('subject', $template);
            $this->assertArrayHasKey('message', $template);
            $this->assertArrayHasKey('senderName', $template);
            $this->assertArrayHasKey('senderEmail', $template);
            $this->assertArrayHasKey('replyToEmail', $template);
            $this->assertArrayHasKey('replyToName', $template);
        }
    }

    public function testListEmailTemplatesSeparatesLocales(): void
    {
        $this->ensureSMTPEnabled();

        $runId = \uniqid();
        $enSubject = "Multi-locale EN {$runId}";
        $frSubject = "Multi-locale FR {$runId}";

        $this->assertSame(200, $this->updateEmailTemplate(
            templateId: 'recovery',
            locale: 'en',
            subject: $enSubject,
            message: 'EN body',
        )['headers']['status-code']);

        $this->assertSame(200, $this->updateEmailTemplate(
            templateId: 'recovery',
            locale: 'fr',
            subject: $frSubject,
            message: 'FR body',
        )['headers']['status-code']);

        $response = $this->listEmailTemplates();
        $this->assertSame(200, $response['headers']['status-code']);

        $foundEn = false;
        $foundFr = false;
        foreach ($response['body']['templates'] as $template) {
            if ($template['templateId'] === 'recovery' && $template['locale'] === 'en' && $template['subject'] === $enSubject) {
                $foundEn = true;
            }
            if ($template['templateId'] === 'recovery' && $template['locale'] === 'fr' && $template['subject'] === $frSubject) {
                $foundFr = true;
            }
        }

        $this->assertTrue($foundEn, 'recovery/en must appear in the list');
        $this->assertTrue($foundFr, 'recovery/fr must appear in the list');
    }

    public function testListEmailTemplatesUpdateDoesNotDuplicate(): void
    {
        $this->ensureSMTPEnabled();

        $runId = \uniqid();
        $firstSubject = "First {$runId}";
        $secondSubject = "Second {$runId}";

        $this->assertSame(200, $this->updateEmailTemplate(
            templateId: 'mfaChallenge',
            locale: 'en',
            subject: $firstSubject,
            message: 'Body',
        )['headers']['status-code']);

        $before = $this->listEmailTemplates();
        $this->assertSame(200, $before['headers']['status-code']);
        $beforeTotal = $before['body']['total'];

        $this->assertSame(200, $this->updateEmailTemplate(
            templateId: 'mfaChallenge',
            locale: 'en',
            subject: $secondSubject,
            message: 'Body',
        )['headers']['status-code']);

        $after = $this->listEmailTemplates();
        $this->assertSame(200, $after['headers']['status-code']);

        // Same templateId/locale must remain a single entry, not accumulate.
        $this->assertSame($beforeTotal, $after['body']['total']);

        $matches = \array_values(\array_filter(
            $after['body']['templates'],
            fn ($t) => $t['templateId'] === 'mfaChallenge' && $t['locale'] === 'en',
        ));
        $this->assertCount(1, $matches);
        $this->assertSame($secondSubject, $matches[0]['subject']);
    }

    public function testListEmailTemplatesTotalFalse(): void
    {
        $this->ensureSMTPEnabled();

        // Ensure at least one template exists so `templates` is non-empty.
        $this->assertSame(200, $this->updateEmailTemplate(
            templateId: 'verification',
            locale: 'en',
            subject: 'Total-false subject',
            message: 'Body',
        )['headers']['status-code']);

        $response = $this->listEmailTemplates(total: false);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertIsInt($response['body']['total']);
        $this->assertSame(0, $response['body']['total']);
        $this->assertNotEmpty($response['body']['templates']);
    }

    public function testListEmailTemplatesTotalMatchesCount(): void
    {
        $this->ensureSMTPEnabled();

        $this->assertSame(200, $this->updateEmailTemplate(
            templateId: 'verification',
            locale: 'en',
            subject: 'Match subject',
            message: 'Body',
        )['headers']['status-code']);

        $response = $this->listEmailTemplates();

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(\count($response['body']['templates']), $response['body']['total']);
    }

    public function testListEmailTemplatesWithLimit(): void
    {
        $this->ensureSMTPEnabled();

        $runId = \uniqid();

        $this->assertSame(200, $this->updateEmailTemplate(
            templateId: 'verification',
            locale: 'en',
            subject: "Limit verification {$runId}",
            message: 'Body',
        )['headers']['status-code']);

        $this->assertSame(200, $this->updateEmailTemplate(
            templateId: 'recovery',
            locale: 'en',
            subject: "Limit recovery {$runId}",
            message: 'Body',
        )['headers']['status-code']);

        $response = $this->listEmailTemplates([
            Query::limit(1)->toString(),
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertCount(1, $response['body']['templates']);
        $this->assertGreaterThanOrEqual(2, $response['body']['total']);
    }

    public function testListEmailTemplatesWithOffset(): void
    {
        $this->ensureSMTPEnabled();

        $runId = \uniqid();

        $this->assertSame(200, $this->updateEmailTemplate(
            templateId: 'magicSession',
            locale: 'en',
            subject: "Offset magic {$runId}",
            message: 'Body',
        )['headers']['status-code']);

        $this->assertSame(200, $this->updateEmailTemplate(
            templateId: 'sessionAlert',
            locale: 'en',
            subject: "Offset session {$runId}",
            message: 'Body',
        )['headers']['status-code']);

        $listAll = $this->listEmailTemplates();
        $this->assertSame(200, $listAll['headers']['status-code']);
        $totalAll = \count($listAll['body']['templates']);

        $listOffset = $this->listEmailTemplates([
            Query::offset(1)->toString(),
        ]);

        $this->assertSame(200, $listOffset['headers']['status-code']);
        $this->assertCount($totalAll - 1, $listOffset['body']['templates']);
        $this->assertSame($listAll['body']['total'], $listOffset['body']['total']);
    }

    public function testListEmailTemplatesOnlyReturnsCustomizedTemplates(): void
    {
        $this->ensureSMTPEnabled();

        // Seed exactly one template so we have a stable marker to count against.
        $marker = 'Customized-only ' . \uniqid();
        $this->assertSame(200, $this->updateEmailTemplate(
            templateId: 'otpSession',
            locale: 'en',
            subject: $marker,
            message: 'Body',
        )['headers']['status-code']);

        $response = $this->listEmailTemplates();
        $this->assertSame(200, $response['headers']['status-code']);

        // Every returned entry must be a real stored template (has templateId+locale set,
        // not a synthesized default row for every possible type).
        foreach ($response['body']['templates'] as $template) {
            $this->assertNotEmpty($template['templateId']);
            $this->assertNotEmpty($template['locale']);
        }

        // A `(templateId, locale)` pair that has never been customized in this test
        // run must NOT show up. 'otpSession'/'pt-br' has no writer anywhere in the file.
        $uncustomized = \array_filter(
            $response['body']['templates'],
            fn ($t) => $t['templateId'] === 'otpSession' && $t['locale'] === 'pt-br',
        );
        $this->assertEmpty($uncustomized, 'uncustomized (templateId, locale) pairs must not appear');
    }

    public function testListEmailTemplatesWithoutAuthentication(): void
    {
        $response = $this->listEmailTemplates(authenticated: false);

        $this->assertSame(401, $response['headers']['status-code']);
    }

    // Backwards compatibility (x-appwrite-response-format: 1.9.1)

    public function testGetEmailTemplateLegacyResponseFormat(): void
    {
        $response = $this->client->call(
            Client::METHOD_GET,
            '/project/templates/email/verification',
            \array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-response-format' => '1.9.1',
            ], $this->getHeaders()),
        );

        $this->assertSame(200, $response['headers']['status-code']);
        // The 1.9.1 response filter renames templateId -> type and strips replyToName.
        $this->assertArrayHasKey('type', $response['body']);
        $this->assertArrayNotHasKey('templateId', $response['body']);
        $this->assertArrayNotHasKey('replyToName', $response['body']);
        $this->assertSame('verification', $response['body']['type']);
        $this->assertSame('en', $response['body']['locale']);
    }

    public function testUpdateEmailTemplateLegacyRequestAndResponse(): void
    {
        $this->ensureSMTPEnabled();

        // Legacy clients send `type` + `replyTo`; request filter maps both.
        $response = $this->client->call(
            Client::METHOD_PATCH,
            '/project/templates/email',
            \array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-response-format' => '1.9.1',
            ], $this->getHeaders()),
            [
                'type' => 'magicSession',
                'locale' => 'en',
                'subject' => 'Legacy subject',
                'message' => 'Legacy body',
                'senderName' => 'Legacy Sender',
                'senderEmail' => 'legacy-sender@appwrite.io',
                'replyTo' => 'legacy-reply@appwrite.io',
            ],
        );

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertArrayHasKey('type', $response['body']);
        $this->assertArrayNotHasKey('templateId', $response['body']);
        $this->assertArrayHasKey('replyTo', $response['body']);
        $this->assertArrayNotHasKey('replyToEmail', $response['body']);
        $this->assertArrayNotHasKey('replyToName', $response['body']);
        $this->assertSame('magicSession', $response['body']['type']);
        $this->assertSame('Legacy subject', $response['body']['subject']);
        $this->assertSame('Legacy body', $response['body']['message']);
        $this->assertSame('Legacy Sender', $response['body']['senderName']);
        $this->assertSame('legacy-sender@appwrite.io', $response['body']['senderEmail']);
        $this->assertSame('legacy-reply@appwrite.io', $response['body']['replyTo']);

        // Modern clients see the new field names for the exact same record.
        $modern = $this->getEmailTemplate('magicSession', 'en');
        $this->assertSame('magicSession', $modern['body']['templateId']);
        $this->assertSame('legacy-reply@appwrite.io', $modern['body']['replyToEmail']);
    }

    public function testUpdateEmailTemplateLegacyInvalidType(): void
    {
        $this->ensureSMTPEnabled();

        $response = $this->client->call(
            Client::METHOD_PATCH,
            '/project/templates/email',
            \array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-response-format' => '1.9.1',
            ], $this->getHeaders()),
            [
                'type' => 'notATemplate',
                'locale' => 'en',
                'subject' => 'Subject',
                'message' => 'Message',
            ],
        );

        $this->assertSame(400, $response['headers']['status-code']);
    }

    // Session alert integration

    public function testSessionAlertUsesCustomTemplatePerLocale(): void
    {
        $this->ensureSMTPEnabled();

        // session-alerts lives under /projects (console scope), so it's driven with the
        // root console session rather than the current test's project-scoped headers.
        $alertsResponse = $this->client->call(
            Client::METHOD_PATCH,
            '/projects/' . $this->getProject()['$id'] . '/auth/session-alerts',
            [
                'origin' => 'http://localhost',
                'content-type' => 'application/json',
                'x-appwrite-project' => 'console',
                'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            ],
            ['enabled' => true],
        );
        $this->assertSame(200, $alertsResponse['headers']['status-code'], 'failed to enable session alerts');

        $runId = \uniqid();
        $enSubject = "EN alert subject {$runId}";
        $enMessage = "EN alert body marker {$runId}";
        $skSubject = "SK alert subject {$runId}";
        $skMessage = "SK alert body marker {$runId}";

        // Configure custom EN template via the default-locale path (omit `locale`).
        $enUpdate = $this->updateEmailTemplate(
            templateId: 'sessionAlert',
            locale: null,
            subject: $enSubject,
            message: $enMessage,
        );
        $this->assertSame(200, $enUpdate['headers']['status-code']);
        $this->assertSame('en', $enUpdate['body']['locale']);

        // Configure custom SK template explicitly.
        $skUpdate = $this->updateEmailTemplate(
            templateId: 'sessionAlert',
            locale: 'sk',
            subject: $skSubject,
            message: $skMessage,
        );
        $this->assertSame(200, $skUpdate['headers']['status-code']);

        // Matrix of request-time locales and the custom template each one must resolve to.
        // `de` has no custom template stored, so it must fall back to the `en` custom template.
        $cases = [
            ['requestLocale' => 'en',  'expectedSubject' => $enSubject, 'expectedMessageMarker' => $enMessage],
            ['requestLocale' => null,  'expectedSubject' => $enSubject, 'expectedMessageMarker' => $enMessage],
            ['requestLocale' => 'sk',  'expectedSubject' => $skSubject, 'expectedMessageMarker' => $skMessage],
            ['requestLocale' => 'de',  'expectedSubject' => $enSubject, 'expectedMessageMarker' => $enMessage],
        ];

        foreach ($cases as $case) {
            $localeLabel = $case['requestLocale'] ?? 'none';
            $email = "session-alert-{$runId}-{$localeLabel}@appwrite.io";
            $password = 'password123';

            // Fresh user per case so the session count starts at zero.
            $create = $this->client->call(Client::METHOD_POST, '/account', [
                'origin' => 'http://localhost',
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-dev-key' => $this->getProject()['devKey'] ?? '',
            ], [
                'userId' => ID::unique(),
                'email' => $email,
                'password' => $password,
                'name' => 'Session Alert ' . $localeLabel,
            ]);
            $this->assertSame(201, $create['headers']['status-code'], "create user ({$localeLabel})");

            // First session must NOT trigger an alert (count === 1 returns early).
            $first = $this->client->call(Client::METHOD_POST, '/account/sessions/email', [
                'origin' => 'http://localhost',
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], [
                'email' => $email,
                'password' => $password,
            ]);
            $this->assertSame(201, $first['headers']['status-code'], "first session ({$localeLabel})");

            // Second session — this one triggers the alert, with the test's request locale.
            $headers = [
                'origin' => 'http://localhost',
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ];
            if ($case['requestLocale'] !== null) {
                $headers['x-appwrite-locale'] = $case['requestLocale'];
            }
            $second = $this->client->call(Client::METHOD_POST, '/account/sessions/email', $headers, [
                'email' => $email,
                'password' => $password,
            ]);
            $this->assertSame(201, $second['headers']['status-code'], "second session ({$localeLabel})");

            // The custom subject is uniquely tagged per run, so matching it proves both
            // that an alert was sent and that the correct locale template was resolved.
            $received = $this->getLastEmailByAddress($email, function ($mail) use ($case) {
                $this->assertSame($case['expectedSubject'], $mail['subject']);
            });

            $this->assertSame($case['expectedSubject'], $received['subject'], "subject ({$localeLabel})");
            $this->assertStringContainsString(
                $case['expectedMessageMarker'],
                $received['text'] . $received['html'],
                "message marker ({$localeLabel})",
            );
        }
    }

    // Helpers

    protected function getEmailTemplate(string $templateId, ?string $locale = null, bool $authenticated = true): mixed
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

        return $this->client->call(Client::METHOD_GET, '/project/templates/email/' . $templateId, $headers, $params);
    }

    protected function listEmailTemplates(?array $queries = null, ?bool $total = null, bool $authenticated = true): mixed
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
        string $templateId,
        ?string $locale = null,
        ?string $subject = null,
        ?string $message = null,
        ?string $senderName = null,
        ?string $senderEmail = null,
        ?string $replyToEmail = null,
        ?string $replyToName = null,
        bool $authenticated = true,
    ): mixed {
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ];

        if ($authenticated) {
            $headers = \array_merge($headers, $this->getHeaders());
        }

        $params = ['templateId' => $templateId];

        foreach (['locale', 'subject', 'message', 'senderName', 'senderEmail', 'replyToEmail', 'replyToName'] as $key) {
            if (!\is_null(${$key})) {
                $params[$key] = ${$key};
            }
        }

        return $this->client->call(Client::METHOD_PATCH, '/project/templates/email', $headers, $params);
    }

    // Console email template (default) tests

    public function testGetConsoleEmailTemplate(): void
    {
        $response = $this->getConsoleEmailTemplate('verification', 'en');

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('verification', $response['body']['templateId']);
        $this->assertSame('en', $response['body']['locale']);
        $this->assertNotEmpty($response['body']['subject']);
        $this->assertNotEmpty($response['body']['message']);
        $this->assertSame('', $response['body']['senderName']);
        $this->assertSame('', $response['body']['senderEmail']);
        $this->assertSame('', $response['body']['replyToEmail']);
        $this->assertSame('', $response['body']['replyToName']);
    }

    public function testGetConsoleEmailTemplateIgnoresCustomOverride(): void
    {
        $this->ensureSMTPEnabled();

        // Set a custom override on the project template.
        $this->updateEmailTemplate(
            templateId: 'recovery',
            locale: 'en',
            subject: 'Custom subject',
            message: 'Custom message',
            senderName: 'Custom Sender',
            senderEmail: 'custom@appwrite.io',
        );

        // Console endpoint must always return the built-in default, not the override.
        $response = $this->getConsoleEmailTemplate('recovery', 'en');

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('recovery', $response['body']['templateId']);
        $this->assertNotSame('Custom subject', $response['body']['subject']);
        $this->assertSame('', $response['body']['senderName']);
        $this->assertSame('', $response['body']['senderEmail']);
    }

    public function testGetConsoleEmailTemplateDefaultLocale(): void
    {
        $response = $this->getConsoleEmailTemplate('magicSession');

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('en', $response['body']['locale']);
        $this->assertNotEmpty($response['body']['subject']);
    }

    public function testGetConsoleEmailTemplateNonDefaultLocale(): void
    {
        $response = $this->getConsoleEmailTemplate('verification', 'fr');

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('verification', $response['body']['templateId']);
        $this->assertSame('fr', $response['body']['locale']);
        $this->assertNotEmpty($response['body']['subject']);
        $this->assertNotEmpty($response['body']['message']);
    }

    public function testGetConsoleEmailTemplateAllTypes(): void
    {
        $types = [
            'verification',
            'magicSession',
            'recovery',
            'invitation',
            'mfaChallenge',
            'sessionAlert',
            'otpSession',
        ];

        foreach ($types as $type) {
            $response = $this->getConsoleEmailTemplate($type, 'en');
            $this->assertSame(200, $response['headers']['status-code'], "type={$type}");
            $this->assertNotEmpty($response['body']['subject'], "type={$type} must have subject");
            $this->assertNotEmpty($response['body']['message'], "type={$type} must have message");
        }
    }

    public function testGetConsoleEmailTemplateInvalidTemplateId(): void
    {
        $response = $this->getConsoleEmailTemplate('invalidTemplate', 'en');

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testGetConsoleEmailTemplateInvalidLocale(): void
    {
        $response = $this->getConsoleEmailTemplate('recovery', 'not-a-locale');

        $this->assertSame(400, $response['headers']['status-code']);
    }

    protected function getConsoleEmailTemplate(string $templateId, ?string $locale = null): mixed
    {
        $params = [];
        if ($locale !== null) {
            $params['locale'] = $locale;
        }

        return $this->client->call(Client::METHOD_GET, '/console/templates/email/' . $templateId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
        ], $params);
    }

    protected function ensureSMTPEnabled(): void
    {
        $this->client->call(
            Client::METHOD_PATCH,
            '/project/smtp',
            \array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()),
            [
                'enabled' => true,
                'senderName' => 'Mailer',
                'senderEmail' => 'mailer@appwrite.io',
                'host' => 'maildev',
                'port' => 1025,
                'username' => 'user',
                'password' => 'password',
            ],
        );
    }
}
