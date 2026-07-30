<?php

namespace Appwrite\Platform\Workers;

use Ahc\Jwt\JWT;
use Appwrite\Template\Template;
use Appwrite\Utopia\Messaging\Adapter\Console as ConsoleAdapter;
use Appwrite\Utopia\Messaging\Adapter\Webhook as WebhookAdapter;
use Appwrite\Utopia\Messaging\Messages\Console as ConsoleMessage;
use Appwrite\Utopia\Messaging\Messages\Webhook as WebhookMessage;
use Exception;
use Swoole\Runtime;
use Throwable;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Validator\UID;
use Utopia\Logger\Log;
use Utopia\Messaging\Adapter\Email as EmailAdapter;
use Utopia\Messaging\Adapter\Email\SMTP;
use Utopia\Messaging\Messages\Email as EmailMessage;
use Utopia\Messaging\Messages\Email\Attachment;
use Utopia\Platform\Action;
use Utopia\Queue\Message;
use Utopia\Registry\Registry;
use Utopia\System\System;

class Notifications extends Action
{
    protected int $previewMaxLen = 150;
    protected string $whitespaceCodes = '&#xa0;&#x200C;&#x200B;&#x200D;&#x200E;&#x200F;&#xFEFF;';

    /**
     * @var array<string, string>
     */
    protected array $richTextParams = [
        'b' => '<strong>',
        '/b' => '</strong>',
    ];

    public static function getName(): string
    {
        return 'notifications';
    }

    public function __construct()
    {
        $this
            ->desc('Notifications worker')
            ->inject('message')
            ->inject('project')
            ->inject('register')
            ->inject('dbForPlatform')
            ->inject('log')
            ->callback($this->action(...));
    }

    public function action(Message $message, Document $project, Registry $register, Database $dbForPlatform, Log $log): void
    {
        if (\class_exists(Runtime::class)) {
            Runtime::setHookFlags(SWOOLE_HOOK_ALL ^ SWOOLE_HOOK_TCP);
        }
        $payload = $message->getPayload();

        if (empty($payload)) {
            throw new Exception('Missing payload');
        }

        $deduplicationKey = $payload['deduplicationKey'] ?? '';
        $messageId = $deduplicationKey !== '' ? \md5($deduplicationKey) : '';

        $recipients = $this->resolveRecipients($payload);
        if (empty($recipients)) {
            throw new Exception('No recipients in payload');
        }

        $failure = null;
        foreach ($recipients as $recipient) {
            $recipient = $this->normalizeRecipient($recipient, $project);
            $channel = $recipient['channel'];

            if ($messageId !== '') {
                if ($channel === NOTIFICATION_TYPE_CONSOLE) {
                    $this->validateConsoleRecipient($recipient);
                }
                $this->validateAlertResource($recipient);
            }

            if ($messageId !== '' && $this->alreadyDelivered($dbForPlatform, self::buildAlertId($messageId, $recipient))) {
                $log->addTag('dedup', 'hit');
                $log->addTag('channel', $channel);
                continue;
            }

            try {
                $alertId = $this->dispatch($recipient, $messageId, $payload, $project, $register, $dbForPlatform, $log);
                if ($messageId !== '' && $channel === NOTIFICATION_TYPE_WEBHOOK && $alertId === null) {
                    $this->persistAlert($dbForPlatform, $messageId, $recipient, $payload, $project);
                }
            } catch (Throwable $error) {
                $log->addTag('channel', $channel);
                $log->addTag('error', $error->getMessage());
                $failure ??= $error;
            }
        }

        if ($failure !== null) {
            throw $failure;
        }
    }

    /**
     * @return array<int, array{address: string, channel: string, signatureKey?: string, resourceType?: string, resourceId?: string, resourceInternalId?: string, parentResourceType?: string, parentResourceId?: string, parentResourceInternalId?: string}>
     */
    private function resolveRecipients(array $payload): array
    {
        $recipients = $payload['recipients'] ?? [];
        if (!empty($recipients)) {
            return $recipients;
        }

        $address = $payload['recipient'] ?? '';
        if ($address === '') {
            return [];
        }

        return [['address' => $address, 'channel' => NOTIFICATION_TYPE_EMAIL]];
    }

    /**
     * @param array{address: string, channel: string, signatureKey?: string, resourceType?: string, resourceId?: string, resourceInternalId?: string, parentResourceType?: string, parentResourceId?: string, parentResourceInternalId?: string} $recipient
     * @return array{address: string, channel: string, signatureKey?: string, resourceType: string, resourceId: string, resourceInternalId: string, parentResourceType: string, parentResourceId: string, parentResourceInternalId: string}
     */
    private function normalizeRecipient(array $recipient, Document $project): array
    {
        $recipient['resourceType'] = $recipient['resourceType'] ?? '';
        $recipient['resourceId'] = $recipient['resourceId'] ?? '';
        $recipient['resourceInternalId'] = $recipient['resourceInternalId'] ?? '';
        $recipient['parentResourceType'] = $recipient['parentResourceType'] ?? RESOURCE_TYPE_PROJECTS;
        $recipient['parentResourceId'] = $recipient['parentResourceId'] ?? (string) $project->getId();
        $recipient['parentResourceInternalId'] = $recipient['parentResourceInternalId'] ?? (string) $project->getSequence();

        if (
            $recipient['channel'] === NOTIFICATION_TYPE_CONSOLE
            && $recipient['resourceId'] === ''
        ) {
            $recipient['resourceType'] = RESOURCE_TYPE_USERS;
            $recipient['resourceId'] = $recipient['address'];
        }

        foreach (['resourceType', 'resourceId', 'resourceInternalId', 'parentResourceType', 'parentResourceId', 'parentResourceInternalId'] as $key) {
            $recipient[$key] = (string) $recipient[$key];
        }

        return $recipient;
    }

    private function alreadyDelivered(Database $dbForPlatform, string $alertId): bool
    {
        return !$dbForPlatform->getDocument('notifications', $alertId)->isEmpty();
    }

    /**
     * Dispatch a single recipient through the channel-appropriate adapter.
     *
     * Returns the alertId when the dispatcher (or its adapter) has already
     * persisted an alert row, so the action loop knows to skip persistence.
     * Returns null when persistence is the caller's responsibility.
     *
     * @param array{address: string, channel: string, signatureKey?: string, resourceType: string, resourceId: string, resourceInternalId: string, parentResourceType: string, parentResourceId: string, parentResourceInternalId: string} $recipient
     */
    protected function dispatch(
        array $recipient,
        string $messageId,
        array $payload,
        Document $project,
        Registry $register,
        Database $dbForPlatform,
        Log $log,
    ): ?string {
        $channel = $recipient['channel'];

        return match ($channel) {
            NOTIFICATION_TYPE_EMAIL => $this->dispatchEmail($recipient, $messageId, $payload, $project, $register, $dbForPlatform, $log),
            NOTIFICATION_TYPE_CONSOLE => $this->dispatchConsole($recipient, $messageId, $payload, $project, $dbForPlatform),
            NOTIFICATION_TYPE_WEBHOOK => $this->dispatchWebhook($recipient, $payload, $log),
            default => throw new Exception('Unsupported notification channel: ' . $channel),
        };
    }

    /**
     * @param array{address: string, channel: string, signatureKey?: string, resourceType: string, resourceId: string, resourceInternalId: string, parentResourceType: string, parentResourceId: string, parentResourceInternalId: string} $recipient
     */
    protected function dispatchEmail(
        array $recipient,
        string $messageId,
        array $payload,
        Document $project,
        Registry $register,
        Database $dbForPlatform,
        Log $log,
    ): ?string {
        $address = $recipient['address'];
        $smtp = $this->resolveSmtpConfig($project, $payload);

        if (empty($smtp) && empty(System::getEnv('_APP_SMTP_HOST'))) {
            $log->addTag('email_skipped', 'no_smtp');
            throw new Exception('Skipped mail processing. No SMTP configuration has been set.');
        }

        $type = empty($smtp) ? 'cloud' : 'smtp';
        $log->addTag('type', $type);

        $protocol = System::getEnv('_APP_OPTIONS_FORCE_HTTPS', 'disabled') === 'disabled' ? 'http' : 'https';
        $consoleHostname = System::getEnv('_APP_CONSOLE_DOMAIN', System::getEnv('_APP_DOMAIN', 'localhost'));

        $subject = $payload['subject'] ?? '';
        $variables = $payload['variables'] ?? [];
        $variables = \array_merge($variables, $payload['templateParams'] ?? []);
        $variables['host'] = $protocol . '://' . $consoleHostname;
        $name = $payload['name'] ?? '';
        $body = $payload['body'] ?? '';
        $preview = $payload['preview'] ?? '';

        $variables['subject'] = $subject;
        $variables['heading'] = $variables['heading'] ?? $subject;
        $variables['year'] = \date('Y');

        $attachment = $payload['attachment'] ?? [];
        $bodyTemplate = $payload['bodyTemplate'] ?? '';
        if (empty($bodyTemplate)) {
            $bodyTemplate = $payload['template'] ?? '';
        }
        if (empty($bodyTemplate)) {
            $bodyTemplate = __DIR__ . '/../../../../app/config/locale/templates/email-base.tpl';
        }

        $bodyTemplate = Template::fromFile($bodyTemplate);
        $bodyTemplate->setParam('{{body}}', $body, escapeHtml: false);
        foreach ($variables as $key => $value) {
            $bodyTemplate->setParam('{{' . $key . '}}', $value, escapeHtml: $key !== 'redirect');
        }
        foreach ($this->richTextParams as $key => $value) {
            $bodyTemplate->setParam('{{' . $key . '}}', $value, escapeHtml: false);
        }

        $previewWhitespace = '';
        if (!empty($preview)) {
            $previewTemplate = Template::fromString($preview);
            foreach ($variables as $key => $value) {
                $previewTemplate->setParam('{{' . $key . '}}', $value);
            }
            $preview = \strip_tags($previewTemplate->render());

            $previewLen = \strlen($preview);
            if ($previewLen < $this->previewMaxLen) {
                $previewWhitespace = \str_repeat($this->whitespaceCodes, $this->previewMaxLen - $previewLen);
            }
        }

        $bodyTemplate->setParam('{{preview}}', $preview);
        $bodyTemplate->setParam('{{previewWhitespace}}', $previewWhitespace, false);

        $body = $bodyTemplate->render();

        $subjectTemplate = Template::fromString($subject);
        foreach ($variables as $key => $value) {
            $subjectTemplate->setParam('{{' . $key . '}}', $value);
        }
        $subject = \strip_tags($subjectTemplate->render());

        $recipientHash = self::buildRecipientHash($recipient);
        $trackingSecret = System::getEnv('_APP_NOTIFICATIONS_TRACKING_SECRET');
        if ($messageId !== '' && !empty($trackingSecret)) {
            $body = $this->injectTrackingLogo($body, $messageId, $recipient['channel'], $recipientHash, $project, $trackingSecret);
        }

        /** @var EmailAdapter $adapter */
        $adapter = empty($smtp)
            ? $register->get('smtp')
            : new SMTP(
                host: $smtp['host'],
                port: (int) $smtp['port'],
                username: $smtp['username'] ?? '',
                password: $smtp['password'] ?? '',
                smtpSecure: $smtp['secure'] ?? '',
                smtpAutoTLS: false,
                xMailer: 'Appwrite Mailer',
                timeout: 10,
                keepAlive: true,
                timelimit: 30,
            );

        $defaultFromEmail = System::getEnv('_APP_SYSTEM_EMAIL_ADDRESS', APP_EMAIL_TEAM);
        $defaultFromName = \urldecode(System::getEnv('_APP_SYSTEM_EMAIL_NAME', APP_NAME . ' Server'));

        $fromEmail = !empty($smtp) ? ($smtp['senderEmail'] ?? $defaultFromEmail) : $defaultFromEmail;
        $fromName = !empty($smtp) ? ($smtp['senderName'] ?? $defaultFromName) : $defaultFromName;
        $replyTo = $defaultFromEmail;
        $replyToName = $defaultFromName;

        $customMailOptions = $payload['customMailOptions'] ?? [];
        if (!\is_array($customMailOptions)) {
            $customMailOptions = [];
        }

        if (!empty($customMailOptions['senderEmail'])) {
            $fromEmail = (string) $customMailOptions['senderEmail'];
        }
        if (!empty($customMailOptions['senderName'])) {
            $fromName = (string) $customMailOptions['senderName'];
        }

        if (!empty($customMailOptions['replyToEmail']) || !empty($customMailOptions['replyToName'])) {
            $replyTo = (string) ($customMailOptions['replyToEmail'] ?? $replyTo);
            $replyToName = (string) ($customMailOptions['replyToName'] ?? $replyToName);
        } elseif (!empty($smtp)) {
            $smtpReplyToEmail = $smtp['replyToEmail'] ?? $smtp['replyTo'] ?? '';
            $replyTo = !empty($smtpReplyToEmail) ? $smtpReplyToEmail : ($smtp['senderEmail'] ?? $replyTo);
            $replyToName = !empty($smtp['replyToName']) ? $smtp['replyToName'] : ($smtp['senderName'] ?? $replyToName);
        }

        $attachments = null;
        if (!empty($attachment['content'] ?? '')) {
            $attachments = [
                new Attachment(
                    name: $attachment['filename'] ?? 'unknown.file',
                    path: '',
                    type: $attachment['type'] ?? 'plain/text',
                    content: \base64_decode($attachment['content']),
                ),
            ];
        }

        $emailMessage = new EmailMessage(
            to: [['email' => $address, 'name' => $name]],
            subject: $subject,
            content: $body,
            fromName: $fromName,
            fromEmail: $fromEmail,
            replyToName: $replyToName,
            replyToEmail: $replyTo,
            attachments: $attachments,
            html: true,
        );

        try {
            $adapter->send($emailMessage);
        } catch (Throwable $error) {
            throw new Exception('Error sending notification: ' . $error->getMessage(), $type === 'smtp' ? 401 : 500);
        }

        if ($messageId !== '') {
            return $this->persistAlert($dbForPlatform, $messageId, $recipient, $payload, $project);
        }

        return null;
    }

    /**
     * @param array{address: string, channel: string, signatureKey?: string, resourceType: string, resourceId: string, resourceInternalId: string, parentResourceType: string, parentResourceId: string, parentResourceInternalId: string} $recipient
     */
    protected function dispatchConsole(array $recipient, string $messageId, array $payload, Document $project, Database $dbForPlatform): ?string
    {
        $this->validateConsoleRecipient($recipient);

        $params = $payload['templateParams'] ?? ($payload['variables'] ?? []);
        $title = self::renderText($payload['subject'] ?? '', $params);
        $body = self::renderText($payload['preview'] ?? '', $params);
        if ($body === '') {
            $body = self::renderText($payload['body'] ?? '', $params);
        }

        $alertId = $messageId !== '' ? self::buildAlertId($messageId, $recipient) : null;
        $recipientHash = $messageId !== '' ? self::buildRecipientHash($recipient) : null;

        $consoleRecipient = [
            'address' => $recipient['address'],
            'resourceType' => $recipient['resourceType'],
            'resourceId' => $recipient['resourceId'],
            'resourceInternalId' => $recipient['resourceInternalId'],
            'parentResourceType' => $recipient['parentResourceType'],
            'parentResourceId' => $recipient['parentResourceId'],
            'parentResourceInternalId' => $recipient['parentResourceInternalId'],
        ];
        if ($alertId !== null) {
            $consoleRecipient['alertId'] = $alertId;
            $consoleRecipient['recipientHash'] = $recipientHash;
        }

        $consoleMessage = new ConsoleMessage(
            recipients: [$consoleRecipient],
            title: $title,
            body: $body,
            type: $payload['type'] ?? 'info',
            messageId: $messageId !== '' ? $messageId : null,
            projectId: $project->getId(),
            projectInternalId: $project->getSequence(),
        );

        $adapter = new ConsoleAdapter($dbForPlatform);
        $result = $adapter->send($consoleMessage);

        if (($result['deliveredTo'] ?? 0) === 0) {
            $error = $result['results'][0]['error'] ?? 'unknown error';
            throw new Exception('Console alert delivery failed: ' . $error);
        }

        return $alertId;
    }

    /**
     * @param array{address: string, channel: string, signatureKey?: string, resourceType: string, resourceId: string, resourceInternalId: string, parentResourceType: string, parentResourceId: string, parentResourceInternalId: string} $recipient
     */
    protected function dispatchWebhook(array $recipient, array $payload, Log $log): ?string
    {
        $address = $recipient['address'];
        $signatureKey = $recipient['signatureKey'] ?? null;

        $body = [
            'subject' => $payload['subject'] ?? '',
            'body' => $payload['body'] ?? '',
            'template' => $payload['template'] ?? '',
            'params' => $payload['templateParams'] ?? [],
            'project' => \is_array($payload['project'] ?? null) ? ($payload['project']['$id'] ?? null) : null,
            'deduplicationKey' => $payload['deduplicationKey'] ?? '',
            'events' => $payload['events'] ?? [],
        ];

        if ($signatureKey === null || $signatureKey === '') {
            $log->addTag('webhook_signed', 'false');
        }

        $message = new WebhookMessage(
            urls: [$address],
            payload: $body,
            signingSecret: $signatureKey,
        );

        $adapter = new WebhookAdapter();
        $result = $adapter->send($message);

        if (($result['deliveredTo'] ?? 0) === 0) {
            $error = $result['results'][0]['error'] ?? 'Unknown error';
            throw new Exception('Webhook delivery failed: ' . $error);
        }

        // Caller persists the alert AFTER successful dispatch.
        return null;
    }

    /**
     * Persist an alert row. Returns the alertId so callers can reference the row.
     *
     * Idempotent: on a duplicate composite-key violation the existing
     * row's id is returned.
     *
     * @param array{address: string, channel: string, signatureKey?: string, resourceType?: string, resourceId?: string, resourceInternalId?: string, parentResourceType?: string, parentResourceId?: string, parentResourceInternalId?: string} $recipient
     */
    protected function persistAlert(Database $dbForPlatform, string $messageId, array $recipient, array $payload, Document $project): string
    {
        $recipient = $this->normalizeRecipient($recipient, $project);

        $channel = $recipient['channel'];

        $alertId = self::buildAlertId($messageId, $recipient);
        $recipientHash = self::buildRecipientHash($recipient);

        $permissions = $this->buildAlertPermissions($recipient['resourceType'], $recipient['resourceId'], $project->getId());
        if (empty($permissions)) {
            $permissions = $payload['permissions'] ?? [];
        }

        $params = $payload['templateParams'] ?? ($payload['variables'] ?? []);
        $body = self::renderText($payload['preview'] ?? '', $params);
        if ($body === '') {
            $body = self::renderText($payload['body'] ?? '', $params);
        }

        $document = new Document([
            '$id' => $alertId,
            '$permissions' => $permissions,
            'messageId' => $messageId,
            'recipientHash' => $recipientHash,
            'type' => $payload['type'] ?? 'info',
            'channel' => $channel,
            'projectId' => $project->getId(),
            'projectInternalId' => $project->getSequence(),
            'resourceType' => $recipient['resourceType'],
            'resourceId' => $recipient['resourceId'],
            'resourceInternalId' => $recipient['resourceInternalId'],
            'parentResourceType' => $recipient['parentResourceType'],
            'parentResourceId' => $recipient['parentResourceId'],
            'parentResourceInternalId' => $recipient['parentResourceInternalId'],
            'title' => self::renderText($payload['subject'] ?? '', $params),
            'body' => $body,
            'read' => false,
        ]);

        try {
            $dbForPlatform->createDocument('notifications', $document);
            return $alertId;
        } catch (DuplicateException) {
            $existing = $dbForPlatform->getDocument('notifications', $alertId);
            return $existing->isEmpty() ? $alertId : $existing->getId();
        }
    }

    /**
     * Build the deterministic alertId composed of the messageId plus a
     * recipient hash.
     *
     * @param array{address: string, channel: string, signatureKey?: string, resourceType: string, resourceId: string, resourceInternalId: string, parentResourceType: string, parentResourceId: string, parentResourceInternalId: string} $recipient
     */
    private static function buildAlertId(string $messageId, array $recipient): string
    {
        return \substr($messageId, 0, 19) . '_' . self::buildRecipientHash($recipient);
    }

    /**
     * @param array{address: string, channel: string, signatureKey?: string, resourceType: string, resourceId: string, resourceInternalId: string, parentResourceType: string, parentResourceId: string, parentResourceInternalId: string} $recipient
     */
    private static function buildRecipientHash(array $recipient): string
    {
        $channel = $recipient['channel'];
        $address = $recipient['address'];

        return \substr(\md5(
            $channel
                . ':' . $address
                . ':' . $recipient['resourceType']
                . ':' . $recipient['resourceId']
                . ':' . $recipient['resourceInternalId']
                . ':' . $recipient['parentResourceType']
                . ':' . $recipient['parentResourceId']
                . ':' . $recipient['parentResourceInternalId']
        ), 0, 16);
    }

    /**
     * @return array<string>
     */
    private function buildAlertPermissions(string $resourceType, string $resourceId, string $projectId): array
    {
        $permissions = [];
        if ($resourceType === RESOURCE_TYPE_USERS) {
            $permissions[] = Permission::read(Role::user($resourceId));
            $permissions[] = Permission::update(Role::user($resourceId));
            $permissions[] = Permission::delete(Role::user($resourceId));
        }
        if ($resourceType === RESOURCE_TYPE_TEAMS) {
            $permissions[] = Permission::read(Role::team($resourceId));
            $permissions[] = Permission::update(Role::team($resourceId, 'owner'));
            $permissions[] = Permission::delete(Role::team($resourceId, 'owner'));
            if ($projectId !== '') {
                $permissions[] = Permission::read(Role::team($resourceId, 'project-' . $projectId . '-owner'));
                $permissions[] = Permission::update(Role::team($resourceId, 'project-' . $projectId . '-owner'));
                $permissions[] = Permission::delete(Role::team($resourceId, 'project-' . $projectId . '-owner'));
            }
        }
        return $permissions;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function resolveSmtpConfig(Document $project, array $payload): array
    {
        $payloadSmtp = $payload['smtp'] ?? [];
        if (\is_array($payloadSmtp) && !empty($payloadSmtp)) {
            return $payloadSmtp;
        }

        $smtp = $project->getAttribute('smtp', []);
        if (!\is_array($smtp) || empty($smtp['enabled'] ?? false)) {
            return [];
        }

        return [
            'host' => $smtp['host'] ?? '',
            'port' => $smtp['port'] ?? '',
            'username' => $smtp['username'] ?? '',
            'password' => $smtp['password'] ?? '',
            'secure' => $smtp['secure'] ?? '',
            'senderEmail' => $smtp['senderEmail'] ?? '',
            'senderName' => $smtp['senderName'] ?? '',
            'replyToEmail' => $smtp['replyToEmail'] ?? $smtp['replyTo'] ?? '',
            'replyToName' => $smtp['replyToName'] ?? '',
        ];
    }

    private function validateConsoleRecipient(array $recipient): void
    {
        $validator = new UID();

        if (!$validator->isValid($recipient['resourceId'])) {
            throw new Exception('Invalid console alert resourceId: ' . $validator->getDescription());
        }
    }

    /**
     * @param array{address: string, channel: string, signatureKey?: string, resourceType: string, resourceId: string, resourceInternalId: string, parentResourceType: string, parentResourceId: string, parentResourceInternalId: string} $recipient
     */
    private function validateAlertResource(array $recipient): void
    {
        foreach (['resourceType', 'resourceId', 'resourceInternalId', 'parentResourceType', 'parentResourceId', 'parentResourceInternalId'] as $key) {
            if ($recipient[$key] === '') {
                throw new Exception('Missing notification ' . $key);
            }
        }
    }

    private static function renderText(string $value, array $params): string
    {
        if ($value !== '' && !empty($params)) {
            $template = Template::fromString($value);
            foreach ($params as $key => $param) {
                $template->setParam('{{' . $key . '}}', (string) $param);
            }
            $value = $template->render();
        }

        return \trim(\strip_tags($value));
    }

    /**
     * Splice a visible tracking logo before the last `</body>` tag (or
     * append at the end if the body has no closing tag). The logo URL
     * carries a signed JWT identifying the notification recipient, which the
     * `/v1/notifications/logos/appwrite` endpoint verifies before marking
     * the notification as read and recording view timestamps.
     */
    private function injectTrackingLogo(string $body, string $messageId, string $channel, string $recipientHash, Document $project, string $trackingSecret): string
    {
        $jwt = (new JWT($trackingSecret, 'HS256', NOTIFICATION_TRACKING_JWT_TTL, 0))
            ->encode([
                'messageId' => $messageId,
                'channel' => $channel,
                'recipientHash' => $recipientHash,
                'projectId' => $project->getId(),
                'projectInternalId' => $project->getSequence(),
                'purpose' => 'notification_track',
            ]);

        $protocol = System::getEnv('_APP_OPTIONS_FORCE_HTTPS', 'disabled') === 'disabled' ? 'http' : 'https';
        $hostname = System::getEnv('_APP_DOMAIN', 'localhost');

        $logoUrl = $protocol . '://' . $hostname . '/v1/notifications/logos/appwrite?jwt=' . \urlencode($jwt);
        $logo = '<img src="' . \htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') . '" width="120" height="28" alt="Appwrite logo" style="display:block;border:0;margin-top:24px" />';

        // Case-insensitive splice before the LAST </body>.
        if (\preg_match('/<\/body\s*>(?!.*<\/body\s*>)/is', $body)) {
            return \preg_replace('/<\/body\s*>(?!.*<\/body\s*>)/is', $logo . '$0', $body, 1) ?? ($body . $logo);
        }

        return $body . $logo;
    }
}
