<?php

namespace Tests\E2E\Services\Project;

use Tests\E2E\Client;
use Utopia\Database\Helpers\ID;

trait SMTPBase
{
    // Update SMTP status tests

    public function testUpdateSMTPStatusEnable(): void
    {
        $response = $this->updateSMTP(
            senderName: 'Test Sender',
            senderEmail: 'sender@example.com',
            host: 'maildev',
            port: 1025,
            enabled: true,
        );

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertSame(true, $response['body']['smtpEnabled']);

        // Cleanup
        $this->updateSMTP(enabled: false);
    }

    public function testUpdateSMTPStatusDisable(): void
    {
        $this->updateSMTP(
            senderName: 'Test Sender',
            senderEmail: 'sender@example.com',
            host: 'maildev',
            port: 1025,
            enabled: true,
        );

        $response = $this->updateSMTP(enabled: false);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertSame(false, $response['body']['smtpEnabled']);
    }

    public function testUpdateSMTPStatusEnableIdempotent(): void
    {
        $first = $this->updateSMTP(
            senderName: 'Test Sender',
            senderEmail: 'sender@example.com',
            host: 'maildev',
            port: 1025,
            enabled: true,
        );
        $this->assertSame(200, $first['headers']['status-code']);
        $this->assertSame(true, $first['body']['smtpEnabled']);

        $second = $this->updateSMTP(enabled: true);
        $this->assertSame(200, $second['headers']['status-code']);
        $this->assertSame(true, $second['body']['smtpEnabled']);

        // Cleanup
        $this->updateSMTP(enabled: false);
    }

    public function testUpdateSMTPStatusDisableIdempotent(): void
    {
        $first = $this->updateSMTP(
            senderName: 'Test Sender',
            senderEmail: 'sender@example.com',
            host: 'maildev',
            port: 1025,
            enabled: false,
        );
        $this->assertSame(200, $first['headers']['status-code']);
        $this->assertSame(false, $first['body']['smtpEnabled']);

        $second = $this->updateSMTP(enabled: false);
        $this->assertSame(200, $second['headers']['status-code']);
        $this->assertSame(false, $second['body']['smtpEnabled']);
    }

    public function testUpdateSMTPStatusResponseModel(): void
    {
        $response = $this->updateSMTP(
            senderName: 'Test Sender',
            senderEmail: 'sender@example.com',
            host: 'maildev',
            port: 1025,
            username: 'user',
            password: 'password',
            enabled: true,
        );

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertArrayHasKey('$id', $response['body']);
        $this->assertArrayHasKey('name', $response['body']);
        $this->assertArrayHasKey('smtpEnabled', $response['body']);
        $this->assertArrayHasKey('smtpSenderName', $response['body']);
        $this->assertArrayHasKey('smtpSenderEmail', $response['body']);
        $this->assertArrayHasKey('smtpReplyToEmail', $response['body']);
        $this->assertArrayHasKey('smtpReplyToName', $response['body']);
        $this->assertArrayHasKey('smtpHost', $response['body']);
        $this->assertArrayHasKey('smtpPort', $response['body']);
        $this->assertArrayHasKey('smtpUsername', $response['body']);
        $this->assertArrayHasKey('smtpPassword', $response['body']);
        // smtpPassword is write-only: the stored password must never leak in responses
        $this->assertSame('', $response['body']['smtpPassword']);
        $this->assertArrayHasKey('smtpSecure', $response['body']);

        // Cleanup
        $this->updateSMTP(enabled: false);
    }

    public function testUpdateSMTPStatusWithoutAuthentication(): void
    {
        $response = $this->updateSMTP(enabled: true, authenticated: false);

        $this->assertSame(401, $response['headers']['status-code']);
    }

    // Update SMTP tests

    public function testUpdateSMTPCredentials(): void
    {
        $response = $this->updateSMTP(
            senderName: 'Test Sender',
            senderEmail: 'sender@example.com',
            host: 'maildev',
            port: 1025,
        );

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertSame(true, $response['body']['smtpEnabled']);
        $this->assertSame('Test Sender', $response['body']['smtpSenderName']);
        $this->assertSame('sender@example.com', $response['body']['smtpSenderEmail']);
        $this->assertSame('maildev', $response['body']['smtpHost']);
        $this->assertSame(1025, $response['body']['smtpPort']);

        // Cleanup
        $this->updateSMTP(enabled: false);
    }

    public function testUpdateSMTPWithOptionalReplyTo(): void
    {
        $response = $this->updateSMTP(
            senderName: 'Full Sender',
            senderEmail: 'sender@example.com',
            host: 'maildev',
            port: 1025,
            replyToEmail: 'reply@example.com',
            replyToName: 'Full Reply',
        );

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(true, $response['body']['smtpEnabled']);
        $this->assertSame('Full Sender', $response['body']['smtpSenderName']);
        $this->assertSame('sender@example.com', $response['body']['smtpSenderEmail']);
        $this->assertSame('reply@example.com', $response['body']['smtpReplyToEmail']);
        $this->assertSame('Full Reply', $response['body']['smtpReplyToName']);
        $this->assertSame('maildev', $response['body']['smtpHost']);
        $this->assertSame(1025, $response['body']['smtpPort']);

        // Cleanup
        $this->updateSMTP(enabled: false);
    }

    public function testUpdateSMTPOverwritesPreviousSettings(): void
    {
        $this->updateSMTP(
            senderName: 'First Sender',
            senderEmail: 'first@example.com',
            host: 'maildev',
            port: 1025,
        );

        $response = $this->updateSMTP(
            senderName: 'Second Sender',
            senderEmail: 'second@example.com',
            host: 'maildev',
            port: 1025,
        );

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('Second Sender', $response['body']['smtpSenderName']);
        $this->assertSame('second@example.com', $response['body']['smtpSenderEmail']);

        // Cleanup
        $this->updateSMTP(enabled: false);
    }

    public function testUpdateSMTPEnablesSMTP(): void
    {
        // Ensure SMTP is disabled
        $this->updateSMTP(
            senderName: 'Test Sender',
            senderEmail: 'sender@example.com',
            host: 'maildev',
            port: 1025,
            enabled: false,
        );

        $response = $this->updateSMTP(
            senderName: 'Test Sender',
            senderEmail: 'sender@example.com',
            host: 'maildev',
            port: 1025,
        );

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(true, $response['body']['smtpEnabled']);

        // Cleanup
        $this->updateSMTP(enabled: false);
    }

    public function testUpdateSMTPResponseModel(): void
    {
        $response = $this->updateSMTP(
            senderName: 'Test Sender',
            senderEmail: 'sender@example.com',
            host: 'maildev',
            port: 1025,
            username: 'user',
            password: 'password',
        );

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertArrayHasKey('$id', $response['body']);
        $this->assertArrayHasKey('name', $response['body']);
        $this->assertArrayHasKey('smtpEnabled', $response['body']);
        $this->assertArrayHasKey('smtpSenderName', $response['body']);
        $this->assertArrayHasKey('smtpSenderEmail', $response['body']);
        $this->assertArrayHasKey('smtpReplyToEmail', $response['body']);
        $this->assertArrayHasKey('smtpReplyToName', $response['body']);
        $this->assertArrayHasKey('smtpHost', $response['body']);
        $this->assertArrayHasKey('smtpPort', $response['body']);
        $this->assertArrayHasKey('smtpUsername', $response['body']);
        $this->assertArrayHasKey('smtpPassword', $response['body']);
        // smtpPassword is write-only: the stored password must never leak in responses
        $this->assertSame('', $response['body']['smtpPassword']);
        $this->assertArrayHasKey('smtpSecure', $response['body']);

        // Cleanup
        $this->updateSMTP(enabled: false);
    }

    public function testUpdateSMTPWithoutAuthentication(): void
    {
        $response = $this->updateSMTP(
            senderName: 'Test',
            senderEmail: 'sender@example.com',
            host: 'maildev',
            port: 1025,
            authenticated: false,
        );

        $this->assertSame(401, $response['headers']['status-code']);
    }

    public function testUpdateSMTPInvalidSenderEmail(): void
    {
        $response = $this->updateSMTP(
            senderName: 'Test',
            senderEmail: 'not-an-email',
            host: 'maildev',
            port: 1025,
        );

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testUpdateSMTPEmptySenderName(): void
    {
        $response = $this->updateSMTP(
            senderName: '',
            senderEmail: 'sender@example.com',
            host: 'maildev',
            port: 1025,
        );

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testUpdateSMTPEmptySenderEmail(): void
    {
        $response = $this->updateSMTP(
            senderName: 'Test',
            senderEmail: '',
            host: 'maildev',
            port: 1025,
        );

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testUpdateSMTPEmptyHost(): void
    {
        $response = $this->updateSMTP(
            senderName: 'Test',
            senderEmail: 'sender@example.com',
            host: '',
            port: 1025,
        );

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testUpdateSMTPInvalidHost(): void
    {
        $response = $this->updateSMTP(
            senderName: 'Test',
            senderEmail: 'sender@example.com',
            host: 'https://myhost.com/v1',
            port: 1025,
        );

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testUpdateSMTPInvalidReplyToEmail(): void
    {
        $response = $this->updateSMTP(
            senderName: 'Test',
            senderEmail: 'sender@example.com',
            host: 'maildev',
            port: 1025,
            replyToEmail: 'not-an-email',
        );

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testUpdateSMTPInvalidSecure(): void
    {
        $response = $this->updateSMTP(
            senderName: 'Test',
            senderEmail: 'sender@example.com',
            host: 'maildev',
            port: 1025,
            secure: 'invalid',
        );

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testUpdateSMTPSenderNameMinLength(): void
    {
        $response = $this->updateSMTP(
            senderName: 'A',
            senderEmail: 'sender@example.com',
            host: 'maildev',
            port: 1025,
        );

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('A', $response['body']['smtpSenderName']);

        // Cleanup
        $this->updateSMTP(enabled: false);
    }

    public function testUpdateSMTPSenderNameMaxLength(): void
    {
        $name = str_repeat('a', 256);
        $response = $this->updateSMTP(
            senderName: $name,
            senderEmail: 'sender@example.com',
            host: 'maildev',
            port: 1025,
        );

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame($name, $response['body']['smtpSenderName']);

        // Cleanup
        $this->updateSMTP(enabled: false);
    }

    public function testUpdateSMTPSenderNameTooLong(): void
    {
        $response = $this->updateSMTP(
            senderName: str_repeat('a', 257),
            senderEmail: 'sender@example.com',
            host: 'maildev',
            port: 1025,
        );

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testUpdateSMTPUsernameMinLength(): void
    {
        $response = $this->updateSMTP(
            senderName: 'Test',
            senderEmail: 'sender@example.com',
            host: 'maildev',
            port: 1025,
            username: 'u',
        );

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('u', $response['body']['smtpUsername']);

        // Cleanup
        $this->updateSMTP(enabled: false);
    }

    public function testUpdateSMTPUsernameMaxLength(): void
    {
        $username = str_repeat('a', 256);
        $response = $this->updateSMTP(
            senderName: 'Test',
            senderEmail: 'sender@example.com',
            host: 'maildev',
            port: 1025,
            username: $username,
        );

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame($username, $response['body']['smtpUsername']);

        // Cleanup
        $this->updateSMTP(enabled: false);
    }

    public function testUpdateSMTPUsernameTooLong(): void
    {
        $response = $this->updateSMTP(
            senderName: 'Test',
            senderEmail: 'sender@example.com',
            host: 'maildev',
            port: 1025,
            username: str_repeat('a', 257),
        );

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testUpdateSMTPUsernameEmpty(): void
    {
        $response = $this->updateSMTP(
            senderName: 'Test',
            senderEmail: 'sender@example.com',
            host: 'maildev',
            port: 1025,
            username: '',
        );

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testUpdateSMTPPasswordMinLength(): void
    {
        $response = $this->updateSMTP(
            senderName: 'Test',
            senderEmail: 'sender@example.com',
            host: 'maildev',
            port: 1025,
            password: 'p',
        );

        $this->assertSame(200, $response['headers']['status-code']);
        // smtpPassword is write-only: the accepted password must not be echoed back
        $this->assertSame('', $response['body']['smtpPassword']);

        // Cleanup
        $this->updateSMTP(enabled: false);
    }

    public function testUpdateSMTPPasswordMaxLength(): void
    {
        $password = str_repeat('a', 256);
        $response = $this->updateSMTP(
            senderName: 'Test',
            senderEmail: 'sender@example.com',
            host: 'maildev',
            port: 1025,
            password: $password,
        );

        $this->assertSame(200, $response['headers']['status-code']);
        // smtpPassword is write-only: the accepted password must not be echoed back
        $this->assertSame('', $response['body']['smtpPassword']);

        // Cleanup
        $this->updateSMTP(enabled: false);
    }

    public function testUpdateSMTPPasswordTooLong(): void
    {
        $response = $this->updateSMTP(
            senderName: 'Test',
            senderEmail: 'sender@example.com',
            host: 'maildev',
            port: 1025,
            password: str_repeat('a', 257),
        );

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testUpdateSMTPPasswordEmpty(): void
    {
        $response = $this->updateSMTP(
            senderName: 'Test',
            senderEmail: 'sender@example.com',
            host: 'maildev',
            port: 1025,
            password: '',
        );

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testUpdateSMTPWithoutSecure(): void
    {
        $response = $this->updateSMTP(
            senderName: 'Test Sender',
            senderEmail: 'sender@example.com',
            host: 'maildev',
            port: 1025,
        );

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('', $response['body']['smtpSecure']);

        // Cleanup
        $this->updateSMTP(enabled: false);
    }

    public function testUpdateSMTPInvalidConnectionEnabled(): void
    {
        $response = $this->updateSMTP(
            senderName: 'Test',
            senderEmail: 'sender@example.com',
            host: 'localhost',
            port: 12345,
            enabled: true,
        );

        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame('project_smtp_config_invalid', $response['body']['type']);
    }

    public function testUpdateSMTPInvalidConnectionDisabled(): void
    {
        $response = $this->updateSMTP(
            senderName: 'Test',
            senderEmail: 'sender@example.com',
            host: 'localhost',
            port: 12345,
            enabled: false,
        );

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(false, $response['body']['smtpEnabled']);
        $this->assertSame('Test', $response['body']['smtpSenderName']);
        $this->assertSame('sender@example.com', $response['body']['smtpSenderEmail']);
        $this->assertSame('localhost', $response['body']['smtpHost']);
        $this->assertSame(12345, $response['body']['smtpPort']);
    }

    public function testUpdateSMTPLegacyReplyToAndResponseFormat(): void
    {
        $headers = \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-response-format' => '1.9.1',
        ], $this->getHeaders());

        // Legacy client sends `replyTo` (not `replyToEmail`). Request filter maps it.
        $response = $this->client->call(
            Client::METHOD_PATCH,
            '/project/smtp',
            $headers,
            [
                'enabled' => true,
                'senderName' => 'Legacy Sender',
                'senderEmail' => 'legacy-sender@example.com',
                'host' => 'maildev',
                'port' => 1025,
                'replyTo' => 'legacy-reply@example.com',
            ],
        );

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(true, $response['body']['smtpEnabled']);
        $this->assertSame('Legacy Sender', $response['body']['smtpSenderName']);
        $this->assertSame('legacy-sender@example.com', $response['body']['smtpSenderEmail']);

        // Response filter must expose smtpReplyTo and strip smtpReplyToEmail / smtpReplyToName.
        $this->assertArrayHasKey('smtpReplyTo', $response['body']);
        $this->assertArrayNotHasKey('smtpReplyToEmail', $response['body']);
        $this->assertArrayNotHasKey('smtpReplyToName', $response['body']);
        $this->assertSame('legacy-reply@example.com', $response['body']['smtpReplyTo']);

        // Sanity-check: a modern (non-legacy) read sees the new field names.
        $modern = $this->updateSMTP(enabled: true);
        $this->assertArrayHasKey('smtpReplyToEmail', $modern['body']);
        $this->assertSame('legacy-reply@example.com', $modern['body']['smtpReplyToEmail']);

        // Cleanup
        $this->updateSMTP(enabled: false);
    }

    public function testCreateSMTPTestLegacyInlineParams(): void
    {
        // Seed the project with a distinct SMTP config so we can prove the
        // inline (1.9.1-style) params take precedence over project config.
        $this->updateSMTP(
            senderName: 'Project Sender',
            senderEmail: 'project-sender@example.com',
            host: 'maildev',
            port: 1025,
            replyToEmail: 'project-reply@example.com',
            replyToName: 'Project Reply',
            enabled: false,
        );

        $recipient = 'legacy-smtp-' . \uniqid() . '@appwrite.io';

        $headers = \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-response-format' => '1.9.1',
        ], $this->getHeaders());

        $response = $this->client->call(
            Client::METHOD_POST,
            '/project/smtp/tests',
            $headers,
            [
                'emails' => [$recipient],
                'senderName' => 'Inline Legacy Sender',
                'senderEmail' => 'inline-legacy@appwrite.io',
                'replyTo' => 'inline-legacy-reply@appwrite.io',
                'host' => 'maildev',
                'port' => 1025,
                'username' => 'user',
                'password' => 'password',
            ],
        );

        $this->assertSame(204, $response['headers']['status-code']);
        $this->assertEmpty($response['body']);

        // Verify the email was sent using the inline params (not project SMTP).
        $email = $this->getLastEmailByAddress($recipient, function ($email) {
            $this->assertSame('Custom SMTP email sample', $email['subject']);
        });

        $this->assertSame('inline-legacy@appwrite.io', $email['from'][0]['address']);
        $this->assertSame('Inline Legacy Sender', $email['from'][0]['name']);
        $this->assertSame('inline-legacy-reply@appwrite.io', $email['replyTo'][0]['address']);
        $this->assertSame('Inline Legacy Sender', $email['replyTo'][0]['name']);

        // Cleanup
        $this->updateSMTP(enabled: false);
    }

    public function testUpdateSMTPBackwardsCompatibilityDisable(): void
    {
        // First enable SMTP
        $this->updateSMTP(
            senderName: 'Test',
            senderEmail: 'sender@example.com',
            host: 'maildev',
            port: 1025,
            enabled: true,
        );

        // Use the deprecated enabled=false parameter to disable
        $response = $this->updateSMTP(
            senderName: 'Test',
            senderEmail: 'sender@example.com',
            host: 'maildev',
            port: 1025,
            enabled: false,
        );

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(false, $response['body']['smtpEnabled']);
    }

    public function testUpdateSMTPRequiredFieldsOptionalAfterConfigured(): void
    {
        // Seed with a known configuration so required fields (host, port, senderEmail) are stored.
        $this->updateSMTP(
            senderName: 'Initial Sender',
            senderEmail: 'initial@example.com',
            host: 'maildev',
            port: 1025,
            enabled: true,
        );

        // Partial update: only update senderName, omitting host/port/senderEmail.
        // Required fields should not be re-required because they are already stored.
        $response = $this->updateSMTP(senderName: 'Updated Sender');

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('Updated Sender', $response['body']['smtpSenderName']);
        $this->assertSame('initial@example.com', $response['body']['smtpSenderEmail']);
        $this->assertSame('maildev', $response['body']['smtpHost']);
        $this->assertSame(1025, $response['body']['smtpPort']);

        // Cleanup
        $this->updateSMTP(enabled: false);
    }

    public function testUpdateSMTPAllParamsOptionalAfterConfigured(): void
    {
        // Seed a configuration so all fields are stored.
        $this->updateSMTP(
            senderName: 'Test Sender',
            senderEmail: 'sender@example.com',
            host: 'maildev',
            port: 1025,
            enabled: true,
        );

        // Issue a PATCH with no params at all. Once previously configured, this must succeed.
        $response = $this->updateSMTP();

        $this->assertSame(200, $response['headers']['status-code']);
        // Previously-set values are preserved
        $this->assertSame('Test Sender', $response['body']['smtpSenderName']);
        $this->assertSame('sender@example.com', $response['body']['smtpSenderEmail']);
        $this->assertSame('maildev', $response['body']['smtpHost']);
        $this->assertSame(1025, $response['body']['smtpPort']);

        // Cleanup
        $this->updateSMTP(enabled: false);
    }

    public function testUpdateSMTPEnabledTrueWithInvalidCredentials(): void
    {
        // Explicitly enabling SMTP with unreachable host/port must throw.
        $response = $this->updateSMTP(
            senderName: 'Test',
            senderEmail: 'sender@example.com',
            host: 'localhost',
            port: 12345,
            enabled: true,
        );

        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame('project_smtp_config_invalid', $response['body']['type']);
    }

    public function testUpdateSMTPEnabledFalseWithInvalidCredentials(): void
    {
        // enabled=false means SMTP is not in use, so invalid credentials must be accepted.
        $response = $this->updateSMTP(
            senderName: 'Test',
            senderEmail: 'sender@example.com',
            host: 'localhost',
            port: 12345,
            enabled: false,
        );

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(false, $response['body']['smtpEnabled']);
        $this->assertSame('localhost', $response['body']['smtpHost']);
        $this->assertSame(12345, $response['body']['smtpPort']);

        // Cleanup (restore valid disabled config)
        $this->updateSMTP(
            senderName: 'Test Sender',
            senderEmail: 'sender@example.com',
            host: 'maildev',
            port: 1025,
            enabled: false,
        );
    }

    public function testUpdateSMTPEnabledNullWithInvalidCredentialsDoesNotThrow(): void
    {
        // Ensure SMTP is currently disabled so we aren't enforcing validation on an enabled config.
        $this->updateSMTP(
            senderName: 'Test',
            senderEmail: 'sender@example.com',
            host: 'maildev',
            port: 1025,
            enabled: false,
        );

        // With enabled omitted (null) and invalid credentials, the request must not throw.
        // SMTP remains disabled because the credentials could not be validated.
        $response = $this->updateSMTP(
            senderName: 'Test',
            senderEmail: 'sender@example.com',
            host: 'localhost',
            port: 12345,
        );

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(false, $response['body']['smtpEnabled']);

        // Cleanup (restore valid disabled config)
        $this->updateSMTP(
            senderName: 'Test Sender',
            senderEmail: 'sender@example.com',
            host: 'maildev',
            port: 1025,
            enabled: false,
        );
    }

    public function testUpdateSMTPEnabledNullWithValidCredentialsAutoEnables(): void
    {
        // Start from a disabled state.
        $this->updateSMTP(
            senderName: 'Test Sender',
            senderEmail: 'sender@example.com',
            host: 'maildev',
            port: 1025,
            enabled: false,
        );

        // With enabled omitted (null) and valid credentials, SMTP must be auto-enabled.
        $response = $this->updateSMTP(
            senderName: 'Test Sender',
            senderEmail: 'sender@example.com',
            host: 'maildev',
            port: 1025,
        );

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(true, $response['body']['smtpEnabled']);

        // Cleanup
        $this->updateSMTP(enabled: false);
    }

    // Create SMTP test tests

    public function testCreateSMTPTest(): void
    {
        // First configure SMTP
        $this->updateSMTP(
            senderName: 'Test Sender',
            senderEmail: 'sender@example.com',
            host: 'maildev',
            port: 1025,
        );

        $response = $this->createSMTPTest(['recipient@example.com']);

        $this->assertSame(204, $response['headers']['status-code']);
        $this->assertEmpty($response['body']);

        // Cleanup
        $this->updateSMTP(enabled: false);
    }

    public function testCreateSMTPTestMultipleRecipients(): void
    {
        // First configure SMTP
        $this->updateSMTP(
            senderName: 'Test Sender',
            senderEmail: 'sender@example.com',
            host: 'maildev',
            port: 1025,
        );

        $response = $this->createSMTPTest([
            'recipient1@example.com',
            'recipient2@example.com',
            'recipient3@example.com',
        ]);

        $this->assertSame(204, $response['headers']['status-code']);
        $this->assertEmpty($response['body']);

        // Cleanup
        $this->updateSMTP(enabled: false);
    }

    public function testCreateSMTPTestWhenSMTPDisabled(): void
    {
        // Ensure SMTP is disabled
        $this->updateSMTP(
            senderName: 'Test Sender',
            senderEmail: 'sender@example.com',
            host: 'maildev',
            port: 1025,
            enabled: false,
        );

        $response = $this->createSMTPTest(['recipient@example.com']);

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testCreateSMTPTestWithoutAuthentication(): void
    {
        $response = $this->createSMTPTest(['recipient@example.com'], false);

        $this->assertSame(401, $response['headers']['status-code']);
    }

    public function testCreateSMTPTestEmptyEmails(): void
    {
        // First configure SMTP
        $this->updateSMTP(
            senderName: 'Test Sender',
            senderEmail: 'sender@example.com',
            host: 'maildev',
            port: 1025,
        );

        $response = $this->createSMTPTest([]);

        $this->assertSame(204, $response['headers']['status-code']);
        $this->assertEmpty($response['body']);

        // Cleanup
        $this->updateSMTP(enabled: false);
    }

    public function testCreateSMTPTestInvalidEmail(): void
    {
        // First configure SMTP
        $this->updateSMTP(
            senderName: 'Test Sender',
            senderEmail: 'sender@example.com',
            host: 'maildev',
            port: 1025,
        );

        $response = $this->createSMTPTest(['not-an-email']);

        $this->assertSame(400, $response['headers']['status-code']);

        // Cleanup
        $this->updateSMTP(enabled: false);
    }

    public function testCreateSMTPTestExceedsMaxEmails(): void
    {
        // First configure SMTP
        $this->updateSMTP(
            senderName: 'Test Sender',
            senderEmail: 'sender@example.com',
            host: 'maildev',
            port: 1025,
        );

        $emails = [];
        for ($i = 1; $i <= 11; $i++) {
            $emails[] = "recipient{$i}@example.com";
        }

        $response = $this->createSMTPTest($emails);

        $this->assertSame(400, $response['headers']['status-code']);

        // Cleanup
        $this->updateSMTP(enabled: false);
    }

    public function testCreateSMTPTestMaxEmails(): void
    {
        // First configure SMTP
        $this->updateSMTP(
            senderName: 'Test Sender',
            senderEmail: 'sender@example.com',
            host: 'maildev',
            port: 1025,
        );

        $emails = [];
        for ($i = 1; $i <= 10; $i++) {
            $emails[] = "recipient{$i}@example.com";
        }

        $response = $this->createSMTPTest($emails);

        $this->assertSame(204, $response['headers']['status-code']);
        $this->assertEmpty($response['body']);

        // Cleanup
        $this->updateSMTP(enabled: false);
    }

    // Integration tests

    public function testCreateSMTPTestEmailDelivery(): void
    {
        $senderName = 'SMTP Test Sender';
        $senderEmail = 'smtptest@appwrite.io';
        $replyToEmail = 'smtpreply@appwrite.io';
        $replyToName = 'SMTP Reply Team';
        $recipientEmail = 'smtpdelivery-' . \uniqid() . '@appwrite.io';

        // Configure SMTP with reply-to and auth credentials
        $response = $this->updateSMTP(
            senderName: $senderName,
            senderEmail: $senderEmail,
            host: 'maildev',
            port: 1025,
            replyToEmail: $replyToEmail,
            replyToName: $replyToName,
            username: 'user',
            password: 'password',
        );

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(true, $response['body']['smtpEnabled']);

        // Trigger test email
        $response = $this->createSMTPTest([$recipientEmail]);

        $this->assertSame(204, $response['headers']['status-code']);

        // Verify email arrived via maildev
        $email = $this->getLastEmailByAddress($recipientEmail, function ($email) {
            $this->assertSame('Custom SMTP email sample', $email['subject']);
        });

        $this->assertSame($senderEmail, $email['from'][0]['address']);
        $this->assertSame($senderName, $email['from'][0]['name']);
        $this->assertSame($replyToEmail, $email['replyTo'][0]['address']);
        $this->assertSame($replyToName, $email['replyTo'][0]['name']);
        $this->assertSame('Custom SMTP email sample', $email['subject']);
        $this->assertStringContainsStringIgnoringCase('working correctly', $email['text']);
        $this->assertStringContainsStringIgnoringCase('working correctly', $email['html']);

        // Cleanup
        $this->updateSMTP(enabled: false);
    }

    public function testMagicURLLoginUsesCustomSMTP(): void
    {
        $senderName = 'Custom Auth Mailer';
        $senderEmail = 'authmailer@appwrite.io';
        $recipientEmail = 'magicurl-' . \uniqid() . '@appwrite.io';

        // Configure custom SMTP with auth credentials
        $response = $this->updateSMTP(
            senderName: $senderName,
            senderEmail: $senderEmail,
            host: 'maildev',
            port: 1025,
            username: 'user',
            password: 'password',
        );

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(true, $response['body']['smtpEnabled']);

        // Trigger MagicURL login as a client (no auth headers needed)
        $response = $this->client->call(Client::METHOD_POST, '/account/tokens/magic-url', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'userId' => ID::unique(),
            'email' => $recipientEmail,
        ]);

        $this->assertSame(201, $response['headers']['status-code']);

        // Verify the email arrived with custom SMTP sender details
        $email = $this->getLastEmailByAddress($recipientEmail, function ($email) {
            $this->assertStringContainsString('Login', $email['subject']);
        });

        $this->assertSame($senderEmail, $email['from'][0]['address']);
        $this->assertSame($senderName, $email['from'][0]['name']);
        $this->assertSame($this->getProject()['name'] . ' Login', $email['subject']);

        // Cleanup
        $this->updateSMTP(enabled: false);
    }

    // Helpers

    protected function updateSMTP(
        ?string $senderName = null,
        ?string $senderEmail = null,
        ?string $host = null,
        ?int $port = null,
        ?string $replyToEmail = null,
        ?string $replyToName = null,
        ?string $username = null,
        ?string $password = null,
        ?string $secure = null,
        ?bool $enabled = null,
        bool $authenticated = true,
    ): mixed {
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ];

        if ($authenticated) {
            $headers = array_merge($headers, $this->getHeaders());
        }

        $params = [];

        foreach (['senderName', 'senderEmail', 'host', 'port', 'replyToEmail', 'replyToName', 'username', 'password', 'secure', 'enabled'] as $key) {
            if (!\is_null(${$key})) {
                $params[$key] = ${$key};
            }
        }

        return $this->client->call(Client::METHOD_PATCH, '/project/smtp', $headers, $params);
    }

    /**
     * @param array<string> $emails
     */
    protected function createSMTPTest(array $emails, bool $authenticated = true): mixed
    {
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ];

        if ($authenticated) {
            $headers = array_merge($headers, $this->getHeaders());
        }

        return $this->client->call(Client::METHOD_POST, '/project/smtp/tests', $headers, [
            'emails' => $emails,
        ]);
    }
}
