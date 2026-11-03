<?php

namespace Appwrite\Platform\Workers;

use Ahc\Jwt\JWT;
use Appwrite\Alert\Unsubscribe;
use Appwrite\Network\Validator\PublicHostname;
use Appwrite\SMTP\Validator\Host;
use Appwrite\Template\Template;
use Appwrite\Utopia\Messaging\Adapter\Console as ConsoleAdapter;
use Appwrite\Utopia\Messaging\Adapter\Webhook as WebhookAdapter;
use Appwrite\Utopia\Messaging\Messages\Console as ConsoleMessage;
use Appwrite\Utopia\Messaging\Messages\Webhook as WebhookMessage;
use Exception;
use Throwable;
use Utopia\Client\Client;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Permission;
use Utopia\Database\Query;
use Utopia\Database\Role;
use Utopia\Database\Validator\UID;
use Utopia\Messaging\Adapter\Email as EmailAdapter;
use Utopia\Messaging\Adapter\Email\SMTP;
use Utopia\Messaging\Adapter\Push as PushAdapter;
use Utopia\Messaging\Exception\InvalidArgumentException;
use Utopia\Messaging\Messages\Email as EmailMessage;
use Utopia\Messaging\Messages\Email\Attachment;
use Utopia\Messaging\Messages\Push as PushMessage;
use Utopia\Messaging\Priority;
use Utopia\Platform\Action;
use Utopia\Queue\Message;
use Utopia\Queue\PermanentFailure;
use Utopia\Registry\Registry;
use Utopia\Span\Span;
use Utopia\System\System;
use Utopia\Telemetry\Adapter as Telemetry;

class Notifications extends Action
{
    protected int $previewMaxLen = 150;
    // Zero-width non-joiner + no-break space only: spam filters such as rspamd treat these as
    // preview padding, while direction marks (U+200E, U+200F) count as hidden text (HIDDEN_TEXT).
    protected string $whitespaceCodes = '&#x200C;&nbsp;';

    /**
     * @var array<string, string>
     */
    protected array $richTextParams = [
        'b' => '<strong>',
        '/b' => '</strong>',
    ];

    private PublicHostname $publicHostname;

    private Telemetry $telemetry;

    private ?PushAdapter $adapterForPush = null;

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
            ->inject('platform')
            ->inject('clientForWebhooks')
            ->inject('publicHostname')
            ->inject('telemetry')
            ->inject('adapterForPush')
            ->callback($this->action(...));
    }

    public function action(Message $message, Document $project, Registry $register, Database $dbForPlatform, array $platform, Client $clientForWebhooks, PublicHostname $publicHostname, Telemetry $telemetry, ?PushAdapter $adapterForPush = null): void
    {
        $this->publicHostname = $publicHostname;
        $this->telemetry = $telemetry;
        $this->adapterForPush = $adapterForPush;

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
                Span::add('dedup', 'hit');
                Span::add('channel', $channel);
                continue;
            }

            try {
                $alertId = $this->dispatch($recipient, $messageId, $payload, $project, $register, $dbForPlatform, $platform, $clientForWebhooks);
                if ($messageId !== '' && $channel === NOTIFICATION_TYPE_WEBHOOK && $alertId === null) {
                    $this->persistAlert($dbForPlatform, $messageId, $recipient, $payload, $project);
                }
            } catch (Throwable $error) {
                Span::add('channel', $channel);
                Span::add('channel.error', $error->getMessage());

                // A retryable failure outranks a permanent one: ending the whole
                // message would abandon a recipient the next attempt can still
                // reach, while the dedup above keeps the retry from repeating the
                // recipients already delivered.
                if ($failure === null || ($failure instanceof PermanentFailure && !$error instanceof PermanentFailure)) {
                    $failure = $error;
                }
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
        array $platform,
        Client $clientForWebhooks,
    ): ?string {
        $channel = $recipient['channel'];

        return match ($channel) {
            NOTIFICATION_TYPE_EMAIL => $this->dispatchEmail($recipient, $messageId, $payload, $project, $register, $dbForPlatform, $platform),
            NOTIFICATION_TYPE_CONSOLE => $this->dispatchConsole($recipient, $messageId, $payload, $project, $dbForPlatform),
            NOTIFICATION_TYPE_WEBHOOK => $this->dispatchWebhook($recipient, $payload, $clientForWebhooks),
            NOTIFICATION_TYPE_PUSH => $this->dispatchPush($recipient, $messageId, $payload, $project, $dbForPlatform),
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
        array $platform,
    ): ?string {
        $address = $recipient['address'];
        $smtp = $this->resolveSmtpConfig($project, $payload);

        if (empty($smtp) && empty(System::getEnv('_APP_SMTP_HOST'))) {
            Span::add('email.skipped', 'no_smtp');
            throw new Exception('Skipped mail processing. No SMTP configuration has been set.');
        }

        $type = empty($smtp) ? 'cloud' : 'smtp';
        Span::add('type', $type);

        $subject = $payload['subject'] ?? '';
        $variables = $payload['variables'] ?? [];
        $variables = \array_merge($variables, $payload['templateParams'] ?? []);
        $variables['host'] = $platform['consoleUrl'] ?? '';
        $name = $payload['name'] ?? '';
        $body = $payload['body'] ?? '';
        $preview = $payload['preview'] ?? '';

        $variables['year'] = \date('Y');

        $subjectTemplate = Template::fromString($subject);
        foreach ($variables as $key => $value) {
            $subjectTemplate->setParam('{{' . $key . '}}', $value, escapeHtml: false);
        }
        $subject = \strip_tags($subjectTemplate->render());

        $heading = $subject;
        if (!empty($variables['heading'])) {
            $headingTemplate = Template::fromString($variables['heading']);
            foreach ($variables as $key => $value) {
                $headingTemplate->setParam('{{' . $key . '}}', $value, escapeHtml: false);
            }
            $heading = $headingTemplate->render(useContent: true);
        }
        $variables['subject'] = $subject;
        $variables['heading'] = $heading;

        // Expand the body's placeholders once, so values are inserted literally
        if (!empty($body)) {
            $bodyContent = Template::fromString($body);
            foreach ($variables as $key => $value) {
                $bodyContent->setParam('{{' . $key . '}}', $value);
            }
            foreach ($this->richTextParams as $key => $value) {
                $bodyContent->setParam('{{' . $key . '}}', $value, escapeHtml: false);
            }
            $body = $bodyContent->render(useContent: true);
        }

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
            $bodyTemplate->setParam('{{' . $key . '}}', $value);
        }
        foreach ($this->richTextParams as $key => $value) {
            $bodyTemplate->setParam('{{' . $key . '}}', $value, escapeHtml: false);
        }
        $bodyTemplate->setParam('{{unsubscribeLink}}', Unsubscribe::link((string) ($variables['unsubscribeUrl'] ?? '')), escapeHtml: false);

        $previewWhitespace = '';
        if (!empty($preview)) {
            $previewTemplate = Template::fromString($preview);
            foreach ($variables as $key => $value) {
                $previewTemplate->setParam('{{' . $key . '}}', $value);
            }
            $preview = \strip_tags($previewTemplate->render());

            // Pad up to previewMaxLen characters; each padding unit is two characters
            $previewLen = \mb_strlen($preview);
            if ($previewLen < $this->previewMaxLen) {
                $previewWhitespace = \str_repeat($this->whitespaceCodes, \intdiv($this->previewMaxLen - $previewLen, 2));
            }
        }

        $bodyTemplate->setParam('{{preview}}', $preview);
        $bodyTemplate->setParam('{{previewWhitespace}}', $previewWhitespace, false);

        $body = $bodyTemplate->render();

        $recipientHash = self::buildRecipientHash($recipient);
        $trackingSecret = System::getEnv('_APP_NOTIFICATIONS_TRACKING_SECRET');
        if ($messageId !== '' && !empty($trackingSecret)) {
            $body = $this->injectTrackingLogo($body, $messageId, $recipient['channel'], $recipientHash, $project, $trackingSecret);
        }

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

        try {
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
                headers: \is_array($payload['headers'] ?? null) ? $payload['headers'] : [],
            );
            $emailMessage
                ->setOrigin(MESSAGE_SEND_TYPE_INTERNAL)
                ->setTemplate(($payload['template'] ?? '') ?: 'unknown');

            // A pooled adapter belongs to this send alone; a project's own SMTP is
            // dialled for it, on the addresses just checked, and closed after.
            $adapter = empty($smtp) ? null : new SMTP(
                host: $smtp['host'],
                port: (int) $smtp['port'],
                username: $smtp['username'] ?? '',
                password: $smtp['password'] ?? '',
                smtpSecure: $smtp['secure'] ?? '',
                smtpAutoTLS: false,
                xMailer: 'Appwrite Mailer',
                timeout: 10,
                keepAlive: false,
                timelimit: 30,
                addresses: new Host($this->publicHostname)->addresses($smtp['host']),
            );

            $telemetry = $this->telemetry;
            // Pooled adapters are shared with other senders, so bind the counter on every use.
            $send = static function (EmailAdapter $adapter) use ($emailMessage, $telemetry): array {
                $adapter->setTelemetry($telemetry);

                return $adapter->send($emailMessage);
            };

            $result = $adapter instanceof EmailAdapter ? $send($adapter) : $register->get('smtp')->use($send);
        } catch (InvalidArgumentException $error) {
            // The address or name can never be delivered, so a retry cannot help.
            Span::add('email.skipped', $error->getType());
            Span::add('email.error', $error->getMessage());

            return null;
        } catch (Throwable $error) {
            throw new Exception('Error sending notification: ' . $error->getMessage(), $type === 'smtp' ? 401 : 500);
        }

        // The adapter records a refusal in its result rather than throwing, so a
        // send nobody accepted has to be failed here, or it is persisted as sent
        // and never retried. As in the Mails worker: a project's own server that
        // refused for good answers every attempt the same way, so retrying only
        // repeats it from the shared egress IP; anything else is worth repeating.
        if (($result['deliveredTo'] ?? 0) === 0) {
            $failure = $result['results'][0] ?? [];
            $error = 'Error sending notification: ' . ($failure['error'] ?? ($result['error'] ?? 'Unknown error'));
            Span::add('email.error', $error);

            if ($type === 'smtp' && ($failure['permanent'] ?? false) === true) {
                throw new PermanentFailure($error, 401);
            }

            throw new Exception($error, $type === 'smtp' ? 401 : 500);
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
    protected function dispatchWebhook(array $recipient, array $payload, Client $clientForWebhooks): ?string
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
            Span::add('webhook.signed', 'false');
        }

        $message = new WebhookMessage(
            urls: [$address],
            payload: $body,
            signingSecret: $signatureKey,
        );

        $adapter = new WebhookAdapter($clientForWebhooks);
        $result = $adapter->send($message);

        if (($result['deliveredTo'] ?? 0) === 0) {
            $error = $result['results'][0]['error'] ?? 'Unknown error';
            throw new Exception('Webhook delivery failed: ' . $error);
        }

        // Caller persists the alert AFTER successful dispatch.
        return null;
    }

    /**
     * Pushes to every signed-in device of the console user the recipient names: their push
     * targets on the console project that have not expired. The user's `beep` prefs mute
     * organizations and projects and turn resolved pushes off for all of them. Each device's
     * push is recorded before it is sent, so a redelivered message pushes nothing twice, even
     * after a crash between the send and the record, while a new device still gets it.
     * A critical monitor's firing goes out with APNs' critical sound. A token the push service
     * reports expired marks its target expired.
     *
     * @param array{address: string, channel: string, signatureKey?: string, resourceType: string, resourceId: string, resourceInternalId: string, parentResourceType: string, parentResourceId: string, parentResourceInternalId: string} $recipient
     */
    protected function dispatchPush(array $recipient, string $messageId, array $payload, Document $project, Database $dbForPlatform): ?string
    {
        $adapter = $this->adapterForPush;
        if ($adapter === null) {
            Span::add('push.skipped', 'disabled');
            return null;
        }

        $push = $payload['push'] ?? [];
        $data = \array_map('strval', $push['data'] ?? []);
        $status = $data['status'] ?? '';

        $user = $dbForPlatform->getDocument('users', $recipient['address']);
        $prefs = $user->getAttribute('prefs', []);
        $prefs = $prefs instanceof Document ? $prefs->getArrayCopy() : (\is_array($prefs) ? $prefs : []);
        $beep = \is_array($prefs['beep'] ?? null) ? $prefs['beep'] : [];
        // Written by the user, so anything that is not a list of strings or a boolean reads as unset.
        $muted = fn (string $key): array => \is_array($beep[$key] ?? null) ? \array_filter($beep[$key], 'is_string') : [];
        if (
            \in_array($data['organizationId'] ?? '', $muted('mutedOrganizationIds'), true)
            || \in_array($data['projectId'] ?? '', $muted('mutedProjectIds'), true)
            || ($status === 'resolved' && ($beep['resolved'] ?? true) === false)
        ) {
            Span::add('push.skipped', 'muted');
            return null;
        }

        $targets = $dbForPlatform->find('targets', [
            Query::equal('userId', [$recipient['address']]),
            Query::equal('providerType', [MESSAGE_TYPE_PUSH]),
            Query::equal('expired', [false]),
            Query::limit(APP_LIMIT_SUBQUERY),
        ]);
        if ($targets === []) {
            Span::add('push.skipped', 'no_targets');
            return null;
        }

        // A device that was not granted Critical Alerts plays it as a normal push.
        $critical = ($push['critical'] ?? false) === true && $status !== 'resolved';
        $failures = [];

        foreach ($targets as $target) {
            $delivery = [...$recipient, 'address' => $target->getId()];

            if ($messageId !== '') {
                // Claimed before sending, so a worker that dies after the push service accepted it
                // leaves the row behind and the redelivery skips this device. A send that fails
                // releases the claim for the retry.
                try {
                    $dbForPlatform->createDocument('notifications', $this->alertDocument($messageId, $delivery, $payload, $project));
                } catch (DuplicateException) {
                    continue;
                }
            }

            $message = new PushMessage(
                to: [(string) $target->getAttribute('identifier')],
                title: (string) ($push['title'] ?? ''),
                body: (string) ($push['body'] ?? ''),
                data: $data === [] ? null : $data,
                sound: $critical ? 'siren.caf' : null,
                tag: ($push['tag'] ?? '') === '' ? null : (string) $push['tag'],
                critical: $critical ? true : null,
                priority: Priority::HIGH,
            );

            try {
                $result = $adapter->send($message);
            } catch (Throwable $error) {
                $failures[] = $target->getId() . ': ' . $error->getMessage();
                $this->release($dbForPlatform, $messageId, $delivery);
                continue;
            }
            $error = (string) ($result['results'][0]['error'] ?? '');

            if (($result['deliveredTo'] ?? 0) > 0) {
                continue;
            }

            if ($error === 'Expired device token') {
                $dbForPlatform->updateDocument('targets', $target->getId(), new Document([
                    'expired' => true,
                ]));
                continue;
            }

            $failures[] = $target->getId() . ': ' . ($error ?: 'Unknown error');
            $this->release($dbForPlatform, $messageId, $delivery);
        }

        Span::add('push.targets', \count($targets));

        if ($failures !== []) {
            throw new Exception('Push delivery failed: ' . \implode(', ', $failures));
        }

        return null;
    }

    /**
     * Removes the claim on a push the service did not accept, so the retry sends it.
     *
     * @param array{address: string, channel: string, signatureKey?: string, resourceType: string, resourceId: string, resourceInternalId: string, parentResourceType: string, parentResourceId: string, parentResourceInternalId: string} $delivery
     */
    private function release(Database $dbForPlatform, string $messageId, array $delivery): void
    {
        if ($messageId !== '') {
            $dbForPlatform->deleteDocument('notifications', self::buildAlertId($messageId, $delivery));
        }
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
        $document = $this->alertDocument($messageId, $recipient, $payload, $project);

        try {
            $dbForPlatform->createDocument('notifications', $document);
            return $document->getId();
        } catch (DuplicateException) {
            $existing = $dbForPlatform->getDocument('notifications', $document->getId());
            return $existing->isEmpty() ? $document->getId() : $existing->getId();
        }
    }

    /**
     * The ledger row recording that `$recipient` was sent the message.
     *
     * @param array{address: string, channel: string, signatureKey?: string, resourceType?: string, resourceId?: string, resourceInternalId?: string, parentResourceType?: string, parentResourceId?: string, parentResourceInternalId?: string} $recipient
     */
    private function alertDocument(string $messageId, array $recipient, array $payload, Document $project): Document
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

        return new Document([
            '$id' => $alertId,
            '$permissions' => $permissions,
            'messageId' => $messageId,
            'recipientHash' => $recipientHash,
            'type' => $payload['type'] ?? 'info',
            'channel' => $channel,
            'projectId' => $project->getId(),
            'projectInternalId' => self::internalId($project),
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

        if ($this->isPlatformMail($payload)) {
            return [];
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

    /**
     * Mail the platform writes to a project's console users, such as an alert
     * rule changing state, goes out through the platform mailer, never through
     * the SMTP server the project configured for its own users.
     *
     * @param array<string, mixed> $payload
     */
    protected function isPlatformMail(array $payload): bool
    {
        return ($payload['template'] ?? '') === MAIL_TEMPLATE_ALERT;
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
     * The project a notification row is filed under, and the tracking token must match. The console project is
     * not stored, so its sequence is empty or its ID ('console'), which the BIGINT column refuses: a record that
     * fails after the email went out retries the message and sends the email again. It is filed under 0.
     */
    private static function internalId(Document $project): string
    {
        return $project->getId() === 'console' ? '0' : (string) $project->getSequence();
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
                'projectInternalId' => self::internalId($project),
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
