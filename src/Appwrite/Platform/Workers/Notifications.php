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

        foreach ($recipients as $recipient) {
            $recipient = $this->normalizeRecipient($recipient);
            $channel = $recipient['channel'];

            if ($messageId !== '' && $this->alreadyDelivered($dbForPlatform, self::buildAlertId($messageId, $recipient))) {
                $log->addTag('dedup', 'hit');
                $log->addTag('channel', $channel);
                continue;
            }

            try {
                $alertId = $this->dispatch($recipient, $messageId, $payload, $project, $register, $dbForPlatform, $log);
                if ($messageId !== '' && $channel === NOTIFICATION_TYPE_WEBHOOK && $alertId === null) {
                    $this->persistAlert($dbForPlatform, $messageId, $recipient, $payload);
                }
            } catch (Throwable $error) {
                $log->addTag('channel', $channel);
                $log->addTag('error', $error->getMessage());
                throw $error;
            }
        }
    }

    /**
     * @return array<int, array{address: string, channel: string, signatureKey?: string, userId?: string, teamId?: string}>
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
     * @param array{address: string, channel: string, signatureKey?: string, userId?: string, teamId?: string} $recipient
     * @return array{address: string, channel: string, signatureKey?: string, userId?: string, teamId?: string}
     */
    private function normalizeRecipient(array $recipient): array
    {
        $recipient['userId'] = $recipient['userId'] ?? '';
        $recipient['teamId'] = $recipient['teamId'] ?? '';

        if (
            $recipient['channel'] === NOTIFICATION_TYPE_CONSOLE
            && $recipient['userId'] === ''
            && $recipient['teamId'] === ''
        ) {
            $recipient['userId'] = $recipient['address'];
        }

        return $recipient;
    }

    private function alreadyDelivered(Database $dbForPlatform, string $alertId): bool
    {
        return !$dbForPlatform->getDocument('alerts', $alertId)->isEmpty();
    }

    /**
     * Dispatch a single recipient through the channel-appropriate adapter.
     *
     * Returns the alertId when the dispatcher (or its adapter) has already
     * persisted an alert row, so the action loop knows to skip persistence.
     * Returns null when persistence is the caller's responsibility.
     *
     * @param array{address: string, channel: string, signatureKey?: string, userId?: string, teamId?: string} $recipient
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
            NOTIFICATION_TYPE_CONSOLE => $this->dispatchConsole($recipient, $messageId, $payload, $dbForPlatform),
            NOTIFICATION_TYPE_WEBHOOK => $this->dispatchWebhook($recipient, $payload, $log),
            default => throw new Exception('Unsupported notification channel: ' . $channel),
        };
    }

    /**
     * @param array{address: string, channel: string, signatureKey?: string, userId?: string, teamId?: string} $recipient
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
        $smtp = $this->resolveSmtpConfig($project);

        if (empty($smtp) && empty(System::getEnv('_APP_SMTP_HOST'))) {
            $log->addTag('email_skipped', 'no_smtp');
            return null;
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

        $deterministicAlertId = $messageId !== ''
            ? self::buildAlertId($messageId, $recipient)
            : null;

        $userId = $recipient['userId'] ?? '';
        $opensslKey = System::getEnv('_APP_OPENSSL_KEY_V1');
        if ($deterministicAlertId !== null && $userId !== '' && !empty($opensslKey)) {
            $body = $this->injectTrackingPixel($body, $deterministicAlertId, $userId, $opensslKey);
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

        if (!empty($smtp)) {
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
            throw new Exception('Error sending notification: ' . $error->getMessage(), 500);
        }

        if ($messageId !== '') {
            return $this->persistAlert($dbForPlatform, $messageId, $recipient, $payload);
        }

        return null;
    }

    /**
     * @param array{address: string, channel: string, signatureKey?: string, userId?: string, teamId?: string} $recipient
     */
    protected function dispatchConsole(array $recipient, string $messageId, array $payload, Database $dbForPlatform): ?string
    {
        $this->validateConsoleRecipient($recipient);

        $project = $payload['project'] ?? null;
        $projectId = \is_array($project) ? ($project['$id'] ?? null) : null;

        $params = $payload['templateParams'] ?? ($payload['variables'] ?? []);
        $title = self::renderText($payload['subject'] ?? '', $params);
        $body = self::renderText($payload['preview'] ?? '', $params);
        if ($body === '') {
            $body = self::renderText($payload['body'] ?? '', $params);
        }

        $userId = $recipient['userId'] ?? $recipient['address'];
        $teamId = $recipient['teamId'] ?? '';
        $alertId = $messageId !== '' ? self::buildAlertId($messageId, $recipient) : null;
        $recipientHash = $messageId !== '' ? self::buildRecipientHash($recipient) : null;

        $consoleRecipient = [];
        if ($userId !== '') {
            $consoleRecipient['userId'] = $userId;
        }
        if ($teamId !== '') {
            $consoleRecipient['teamId'] = $teamId;
        }
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
            projectId: $projectId,
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
     * @param array{address: string, channel: string, signatureKey?: string, userId?: string, teamId?: string} $recipient
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
     * Persist an alert row. Returns the alertId so callers can build a
     * tracking-pixel URL or otherwise reference the row.
     *
     * Idempotent: on a duplicate composite-key violation the existing
     * row's id is returned.
     *
     * @param array{address: string, channel: string, signatureKey?: string, userId?: string, teamId?: string} $recipient
     */
    protected function persistAlert(Database $dbForPlatform, string $messageId, array $recipient, array $payload): string
    {
        $recipient = $this->normalizeRecipient($recipient);

        $project = $payload['project'] ?? null;
        $projectId = \is_array($project) ? ($project['$id'] ?? null) : null;

        $channel = $recipient['channel'];
        $userId = $recipient['userId'] ?? '';
        $teamId = $recipient['teamId'] ?? '';

        $alertId = self::buildAlertId($messageId, $recipient);
        $recipientHash = self::buildRecipientHash($recipient);

        $permissions = $this->buildAlertPermissions($userId, $teamId);
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
            'userId' => $userId,
            'teamId' => $teamId,
            'projectId' => $projectId,
            'title' => self::renderText($payload['subject'] ?? '', $params),
            'body' => $body,
            'read' => false,
        ]);

        try {
            $dbForPlatform->createDocument('alerts', $document);
            return $alertId;
        } catch (DuplicateException) {
            $existing = $dbForPlatform->getDocument('alerts', $alertId);
            return $existing->isEmpty() ? $alertId : $existing->getId();
        }
    }

    /**
     * Build the deterministic alertId composed of the messageId plus a
     * recipient hash. Single source of truth used
     * by both `persistAlert()` and `dispatchEmail()` so the tracking pixel
     * URL matches the eventually-persisted row exactly.
     *
     * @param array{address: string, channel: string, signatureKey?: string, userId?: string, teamId?: string} $recipient
     */
    private static function buildAlertId(string $messageId, array $recipient): string
    {
        return \substr($messageId, 0, 19) . '_' . self::buildRecipientHash($recipient);
    }

    /**
     * @param array{address: string, channel: string, signatureKey?: string, userId?: string, teamId?: string} $recipient
     */
    private static function buildRecipientHash(array $recipient): string
    {
        $channel = $recipient['channel'];
        $address = $recipient['address'];
        $userId = $recipient['userId'] ?? '';
        $teamId = $recipient['teamId'] ?? '';

        return \substr(\md5($channel . ':' . $address . ':' . $userId . ':' . $teamId), 0, 16);
    }

    /**
     * @return array<string>
     */
    private function buildAlertPermissions(string $userId, string $teamId): array
    {
        $permissions = [];
        if ($userId !== '') {
            $permissions[] = Permission::read(Role::user($userId));
            $permissions[] = Permission::update(Role::user($userId));
            $permissions[] = Permission::delete(Role::user($userId));
        }
        if ($teamId !== '') {
            $permissions[] = Permission::read(Role::team($teamId));
            $permissions[] = Permission::update(Role::team($teamId, 'owner'));
            $permissions[] = Permission::delete(Role::team($teamId, 'owner'));
        }
        return $permissions;
    }

    /**
     * Resolve project SMTP config to the wire shape Mails.php expects.
     * ST4 stripped `smtp` and `customMailOptions` from the Notification
     * event payload, so the worker now reads from the project Document.
     * Falls back to env-driven cloud SMTP when the project has not
     * configured custom SMTP.
     *
     * @return array<string, mixed>
     */
    private function resolveSmtpConfig(Document $project): array
    {
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

        foreach (['userId', 'teamId'] as $key) {
            $value = $recipient[$key] ?? '';
            if ($value !== '' && !$validator->isValid($value)) {
                throw new Exception('Invalid console alert ' . $key . ': ' . $validator->getDescription());
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
     * Splice a 1x1 tracking pixel before the last `</body>` tag (or
     * append at the end if the body has no closing tag). The pixel
     * carries a signed JWT identifying the alert and user, which the
     * `/v1/account/alerts/:alertId/track` endpoint verifies before
     * marking the alert as read.
     */
    private function injectTrackingPixel(string $body, string $alertId, string $userId, string $opensslKey): string
    {
        $jwt = (new JWT($opensslKey, 'HS256', ALERT_TRACKING_JWT_TTL, 0))
            ->encode([
                'alertId' => $alertId,
                'userId' => $userId,
                'purpose' => 'alert_track',
            ]);

        $protocol = System::getEnv('_APP_OPTIONS_FORCE_HTTPS', 'disabled') === 'disabled' ? 'http' : 'https';
        $hostname = System::getEnv('_APP_DOMAIN', 'localhost');

        $pixelUrl = $protocol . '://' . $hostname . '/v1/account/alerts/' . $alertId . '/track?jwt=' . \urlencode($jwt);
        $pixel = '<img src="' . \htmlspecialchars($pixelUrl, ENT_QUOTES, 'UTF-8') . '" width="1" height="1" alt="" style="display:none" />';

        // Case-insensitive splice before the LAST </body>.
        if (\preg_match('/<\/body\s*>(?!.*<\/body\s*>)/is', $body)) {
            return \preg_replace('/<\/body\s*>(?!.*<\/body\s*>)/is', $pixel . '$0', $body, 1) ?? ($body . $pixel);
        }

        return $body . $pixel;
    }
}
