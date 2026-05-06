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
use Utopia\Database\Query;
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
     * Tracking pixel JWT lifetime: 30 days.
     */
    private const TRACKING_JWT_TTL = 2592000;

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

        if ($messageId !== '' && $this->alreadyDelivered($dbForPlatform, $messageId)) {
            $log->addTag('dedup', 'hit');
            return;
        }

        $recipients = $this->resolveRecipients($payload);
        if (empty($recipients)) {
            throw new Exception('No recipients in payload');
        }

        foreach ($recipients as $recipient) {
            $channel = $recipient['channel'];
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
     * Look up an existing alert by the indexed `messageId` attribute.
     *
     * Greptile P1 #1: persistAlert and the Console adapter both write
     * compound `$id`s (messageId + recipient hash), so a direct
     * `getDocument($messageId)` would always miss. Query the attribute.
     */
    private function alreadyDelivered(Database $dbForPlatform, string $messageId): bool
    {
        try {
            $matches = $dbForPlatform->find('alerts', [
                Query::equal('messageId', [$messageId]),
                Query::limit(1),
            ]);
            return !empty($matches);
        } catch (Throwable) {
            return false;
        }
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
            throw new Exception('Skipped email notification. No SMTP configuration has been set.');
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

        // Persist alert BEFORE adapter send so the alertId is available for
        // the tracking pixel. Failure to persist still allows the email to
        // go out unsignals (we degrade gracefully).
        $alertId = null;
        if ($messageId !== '') {
            $alertId = $this->persistAlert($dbForPlatform, $messageId, $recipient, $payload);
        }

        // C3 tracking pixel: only injectable when we have a userId AND a
        // persisted alertId AND a signing key.
        $userId = $recipient['userId'] ?? '';
        $opensslKey = System::getEnv('_APP_OPENSSL_KEY_V1');
        if ($alertId !== null && $userId !== '' && !empty($opensslKey)) {
            $body = $this->injectTrackingPixel($body, $alertId, $userId, $opensslKey);
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
            if ($type === 'smtp') {
                throw new Exception('Error sending notification: ' . $error->getMessage(), 401);
            }
            throw new Exception('Error sending notification: ' . $error->getMessage(), 500);
        }

        return $alertId;
    }

    /**
     * @param array{address: string, channel: string, signatureKey?: string, userId?: string, teamId?: string} $recipient
     */
    protected function dispatchConsole(array $recipient, string $messageId, array $payload, Database $dbForPlatform): ?string
    {
        $project = $payload['project'] ?? null;
        $projectId = \is_array($project) ? ($project['$id'] ?? null) : null;

        $title = $payload['subject'] ?? '';
        $body = $payload['body'] ?? '';
        $params = $payload['templateParams'] ?? ($payload['variables'] ?? []);
        if ($title !== '' && !empty($params)) {
            $rendered = Template::fromString($title);
            foreach ($params as $key => $value) {
                $rendered->setParam('{{' . $key . '}}', (string) $value);
            }
            $title = \strip_tags($rendered->render());
        }

        $userId = $recipient['userId'] ?? $recipient['address'];
        $teamId = $recipient['teamId'] ?? '';

        $consoleRecipient = [];
        if ($userId !== '') {
            $consoleRecipient['userId'] = $userId;
        }
        if ($teamId !== '') {
            $consoleRecipient['teamId'] = $teamId;
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

        // Greptile P1 #4: surface adapter failures. The Console adapter
        // catches per-recipient exceptions and reports zero deliveries via
        // `deliveredTo`; without this throw the worker would silently
        // succeed on a hard write failure.
        if (($result['deliveredTo'] ?? 0) === 0) {
            $error = $result['results'][0]['error'] ?? 'unknown error';
            throw new Exception('Console alert delivery failed: ' . $error);
        }

        // Adapter persisted the alert, so the action loop must NOT
        // call persistAlert again (Greptile P1 #3).
        return null;
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
        $project = $payload['project'] ?? null;
        $projectId = \is_array($project) ? ($project['$id'] ?? null) : null;

        $channel = $recipient['channel'];
        $address = $recipient['address'];
        $userId = $recipient['userId'] ?? '';
        $teamId = $recipient['teamId'] ?? '';

        // Console alerts derive userId from address when no explicit
        // userId is supplied (matches Console adapter's own bookkeeping).
        if ($channel === NOTIFICATION_TYPE_CONSOLE && $userId === '' && $teamId === '') {
            $userId = $address;
        }

        $idSuffix = \substr(\md5($channel . ':' . $address . ':' . $userId . ':' . $teamId), 0, 8);
        $alertId = $messageId . '_' . $idSuffix;

        $permissions = $this->buildAlertPermissions($userId, $teamId);
        if (empty($permissions)) {
            $permissions = $payload['permissions'] ?? [];
        }

        $document = new Document([
            '$id' => $alertId,
            '$permissions' => $permissions,
            'messageId' => $messageId,
            'type' => $payload['type'] ?? 'info',
            'channel' => $channel,
            'userId' => $userId !== '' ? $userId : null,
            'teamId' => $teamId !== '' ? $teamId : null,
            'projectId' => $projectId,
            'title' => $payload['subject'] ?? '',
            'body' => $payload['body'] ?? '',
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

    /**
     * Splice a 1x1 tracking pixel before the last `</body>` tag (or
     * append at the end if the body has no closing tag). The pixel
     * carries a 30-day JWT identifying the alert and user, which the
     * `/v1/account/alerts/:alertId/track` endpoint verifies before
     * marking the alert as read.
     */
    private function injectTrackingPixel(string $body, string $alertId, string $userId, string $opensslKey): string
    {
        $jwt = (new JWT($opensslKey, 'HS256', self::TRACKING_JWT_TTL, 0))
            ->encode([
                'alertId' => $alertId,
                'userId' => $userId,
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
