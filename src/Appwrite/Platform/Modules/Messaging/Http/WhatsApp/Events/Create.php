<?php

namespace Appwrite\Platform\Modules\Messaging\Http\WhatsApp\Events;

use Appwrite\Auth\PhoneOTPChannel;
use Appwrite\Event\Message\Messaging as MessagingMessage;
use Appwrite\Event\Publisher\Messaging as MessagingPublisher;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Cache\Cache;
use Utopia\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Platform\Scope\HTTP;
use Utopia\Span\Span;
use Utopia\System\System;

/**
 * Meta accepts a WhatsApp message before it knows whether the recipient can receive it, and
 * reports an undelivered one here rather than in the send response. That makes this endpoint
 * the only place an OTP the recipient never got can still be rescued onto SMS.
 */
class Create extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'createWhatsAppEvent';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/messaging/whatsapp/events')
            ->desc('Receive a WhatsApp delivery status')
            // Deliberately not in the messaging group: its init hook rejects a request that
            // carries no project header, and Meta has no project to name.
            ->groups(['api'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('abuse-key', 'ip:{ip}')
            ->label('abuse-limit', 600)
            ->label('abuse-time', 60)
            ->inject('request')
            ->inject('response')
            ->inject('cache')
            ->inject('dbForPlatform')
            ->inject('authorization')
            ->inject('publisherForMessaging')
            ->callback($this->action(...));
    }

    public function action(
        Request $request,
        Response $response,
        Cache $cache,
        Database $dbForPlatform,
        Authorization $authorization,
        MessagingPublisher $publisherForMessaging
    ): void {
        $secret = System::getEnv('_APP_WHATSAPP_APP_SECRET', '');

        if (empty($secret)) {
            throw new Exception(Exception::GENERAL_ACCESS_FORBIDDEN, 'WhatsApp webhooks are not configured on this instance');
        }

        $payload = $request->getRawPayload();

        if (!$this->isSigned($payload, $request->getHeaderLine('x-hub-signature-256', ''), $secret)) {
            Span::add('messaging.whatsapp.event.signature.valid', false);
            throw new Exception(Exception::GENERAL_ACCESS_FORBIDDEN, 'Invalid webhook payload signature');
        }

        foreach ($this->failures($payload) as $failure) {
            $this->fallback($failure, $cache, $dbForPlatform, $authorization, $publisherForMessaging);
        }

        // Anything other than a 200 puts Meta into a 36 hour retry, so a payload this instance
        // cannot act on is still acknowledged. Only a bad signature is refused above.
        $response->noContent();
    }

    private function isSigned(string $payload, string $signature, string $secret): bool
    {
        if (!\str_starts_with($signature, 'sha256=')) {
            return false;
        }

        return \hash_equals(\hash_hmac('sha256', $payload, $secret), \substr($signature, 7));
    }

    /**
     * The failed statuses in a notification, each carrying the callback data planted when the
     * message was sent. Meta batches statuses from several messages into one request.
     *
     * @return array<int, array{callback: string, undeliverable: bool}>
     */
    private function failures(string $payload): array
    {
        $body = \json_decode($payload, true);

        if (!\is_array($body)) {
            return [];
        }

        $failures = [];

        foreach ($body['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                foreach ($change['value']['statuses'] ?? [] as $status) {
                    if (($status['status'] ?? '') !== 'failed') {
                        continue;
                    }

                    $callback = $status['biz_opaque_callback_data'] ?? '';

                    if (!\is_string($callback) || $callback === '') {
                        continue;
                    }

                    $codes = \array_column($status['errors'] ?? [], 'code');

                    $failures[] = [
                        'callback' => $callback,
                        'undeliverable' => \in_array(PHONE_OTP_WHATSAPP_UNDELIVERABLE_CODE, $codes, false),
                    ];
                }
            }
        }

        return $failures;
    }

    /**
     * @param array{callback: string, undeliverable: bool} $failure
     */
    private function fallback(
        array $failure,
        Cache $cache,
        Database $dbForPlatform,
        Authorization $authorization,
        MessagingPublisher $publisherForMessaging
    ): void {
        [$projectId, $messageId] = \array_pad(\explode(':', $failure['callback'], 2), 2, '');

        if ($projectId === '' || $messageId === '') {
            return;
        }

        $key = PHONE_OTP_WHATSAPP_FALLBACK_KEY . ':' . $projectId . ':' . $messageId;
        $stashed = $cache->load($key, TOKEN_EXPIRATION_OTP);

        // Nothing to send: the policy had no SMS fallback, the code has since expired, or a
        // duplicate of this notification already claimed it.
        if (empty($stashed['message'])) {
            return;
        }

        // Meta retries and does not order its notifications, so the claim has to happen before
        // the send. Purging first means a duplicate arriving mid-flight finds nothing.
        $cache->purge($key);

        $project = $authorization->skip(fn () => $dbForPlatform->getDocument('projects', $projectId));

        if ($project->isEmpty()) {
            return;
        }

        $recipients = $stashed['recipients'] ?? [];

        if ($failure['undeliverable']) {
            foreach ($recipients as $recipient) {
                $cache->save(
                    PHONE_OTP_WHATSAPP_UNREACHABLE_KEY . ':' . PhoneOTPChannel::unreachableKey((string) $recipient),
                    ['at' => \time()],
                    ttl: PHONE_OTP_WHATSAPP_UNREACHABLE_TTL,
                );
            }
        }

        Span::add('messaging.whatsapp.event.fallback', $messageId);
        Console::info('WhatsApp OTP for project ' . $projectId . ' was not delivered, falling back to SMS');

        $publisherForMessaging->enqueue(new MessagingMessage(
            type: MESSAGE_SEND_TYPE_INTERNAL,
            project: $project,
            message: new Document($stashed['message']),
            recipients: $recipients,
            channel: PHONE_OTP_CHANNEL_SMS,
            fallback: false,
        ));
    }
}
