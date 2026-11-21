<?php

declare(strict_types=1);

namespace Utopia\Messaging\Adapter\Email;

use Utopia\Messaging\Adapter\Email as EmailAdapter;
use Utopia\Messaging\Adapter\Email\Bird\Category;
use Utopia\Messaging\Adapter\SMS\Bird as BirdSMS;
use Utopia\Messaging\Exception\InvalidArgumentException;
use Utopia\Messaging\Messages\Email as EmailMessage;
use Utopia\Messaging\Response;

// Reference Material
// https://bird.com/docs/guides/email/sending-email
// https://bird.com/docs/guides/email/sending-bulk
// https://bird.com/docs/guides/email/attachments
// https://bird.com/docs/api/errors
class Bird extends EmailAdapter
{
    protected const NAME = 'Bird';

    /**
     * Bird refuses a message above 20 MB. It counts the body and every file
     * after base64 encoding, which grows a file by a third, so 15 MB of files
     * fill a message on their own. Bird does not say whether it means 20 MB
     * or 20 MiB, so this is the smaller of the two.
     */
    private const int MAX_MESSAGE_BYTES = 20 * 1000 * 1000;

    private const string PATH_MESSAGE = '/v1/email/messages';

    private const string PATH_BATCH = '/v1/email/batches';

    /**
     * Bird accepts up to 100 messages per batch request.
     */
    private const int MAX_BATCH_SIZE = 100;

    /**
     * Bird refuses a batch whose JSON body is above 20 MB.
     */
    private const int MAX_BATCH_BYTES = 20 * 1000 * 1000;

    /**
     * Room kept per message for a recipient longer than the first one.
     */
    private const int RECIPIENT_BYTES = 1024;

    private readonly string $endpoint;

    /**
     * @param  string  $apiKey Workspace API key, `bk_{region}_...`, with the `emails:write` scope.
     * @param  Category  $category Why the mail is sent. Bird treats a send that names none as marketing.
     * @param  string|null  $endpoint Overrides the regional host derived from the key, for tests and proxies.
     *
     * @throws \InvalidArgumentException When the key carries no region and no endpoint is given.
     */
    public function __construct(
        private readonly string $apiKey,
        private readonly Category $category = Category::TRANSACTIONAL,
        ?string $endpoint = null,
    ) {
        $this->endpoint = rtrim($endpoint ?? BirdSMS::hostFor($apiKey), '/');

        parent::__construct();
    }

    public function getName(): string
    {
        return static::NAME;
    }

    public function getMaxMessagesPerRequest(): int
    {
        return self::MAX_BATCH_SIZE;
    }

    /**
     * {@inheritdoc}
     *
     * Every To recipient gets a message of their own, so none of them sees
     * the others, and CC and BCC receive a copy of each. One message goes
     * through the single-message endpoint and several go through a batch.
     */
    protected function process(EmailMessage $message): array
    {
        $recipients = $message->getTo();

        if ($recipients === []) {
            throw new InvalidArgumentException(
                InvalidArgumentException::RECIPIENT_EMPTY,
                'Bird needs at least one To recipient.',
            );
        }

        $attachments = $this->attachments($message);
        $payloads = array_map(
            fn (array $to): array => $this->payload($message, $to, $attachments),
            $recipients,
        );

        $refusals = [];
        foreach ($this->batches($payloads) as $batch) {
            $refusals += $this->submit($batch);
        }

        // 422: Bird refused the message as written, so a retry cannot help.
        // With several recipients the refusal stays per-recipient so the rest deliver.
        $only = \count($recipients) === 1 ? array_key_first($recipients) : null;
        if ($only !== null && ($refusals[$only]['statusCode'] ?? 0) === 422) {
            throw new InvalidArgumentException(
                InvalidArgumentException::PROVIDER_REJECTED,
                $refusals[$only]['error'],
                $recipients[$only]['email'],
            );
        }

        $response = new Response($this->getType());

        foreach ($recipients as $index => $to) {
            $response->addResult($to['email'], $refusals[$index]['error'] ?? '');
        }

        $response->setDeliveredTo(\count($recipients) - \count($refusals));

        return $response->toArray();
    }

    /**
     * Split the messages into batches Bird accepts: at most 100 messages and
     * 20 MB of JSON each. The messages differ only in their recipient, so the
     * first one stands for the size of them all.
     *
     * @param  array<int, array<string, mixed>>  $payloads
     * @return array<array<int, array<string, mixed>>>
     */
    private function batches(array $payloads): array
    {
        $size = \strlen(json_encode(reset($payloads), JSON_THROW_ON_ERROR)) + self::RECIPIENT_BYTES;
        $fitting = max(1, min(self::MAX_BATCH_SIZE, intdiv(self::MAX_BATCH_BYTES, $size)));

        return array_chunk($payloads, $fitting, preserve_keys: true);
    }

    /**
     * Send one batch and report the messages Bird did not accept.
     *
     * Bird validates a batch as one unit and refuses all of it when one
     * recipient is unacceptable, without saying which message that was. The
     * messages differ only in their recipient, so a refusal that names `to`
     * is retried one message at a time to find who it applies to; any other
     * refusal is true of every message.
     *
     * @param  array<int, array<string, mixed>>  $payloads
     * @return array<int, array{statusCode: int, error: string}> Keyed like the messages; an accepted message has no entry.
     */
    private function submit(array $payloads): array
    {
        if (\count($payloads) === 1) {
            $result = $this->request(
                method: 'POST',
                url: $this->endpoint . self::PATH_MESSAGE,
                headers: $this->headers(),
                body: reset($payloads),
            );
        } else {
            $result = $this->request(
                method: 'POST',
                url: $this->endpoint . self::PATH_BATCH,
                headers: $this->headers(),
                body: ['messages' => array_values($payloads)],
            );
        }

        if ($result['statusCode'] >= 200 && $result['statusCode'] < 300) {
            return [];
        }

        if (\count($payloads) > 1 && $result['statusCode'] === 422 && $this->field($result) === 'to') {
            $refusals = [];
            foreach ($payloads as $index => $payload) {
                $refusals += $this->submit([$index => $payload]);
            }

            return $refusals;
        }

        return array_fill_keys(array_keys($payloads), [
            'statusCode' => $result['statusCode'],
            'error' => $this->describe($result),
        ]);
    }

    /**
     * @return array<string>
     */
    private function headers(): array
    {
        return [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey,
        ];
    }

    /**
     * @param  array<string, string>  $to
     * @param  array<array<string, string>>  $attachments
     * @return array<string, mixed>
     */
    private function payload(EmailMessage $message, array $to, array $attachments): array
    {
        $payload = [
            'from' => $this->address($message->getFromEmail(), $message->getFromName()),
            'to' => [$this->address($to['email'], $to['name'] ?? null)],
            'subject' => $message->getSubject(),
            ($message->isHtml() ? 'html' : 'text') => $message->getContent(),
            'category' => $this->category->value,
        ];

        if (!\in_array($message->getReplyToEmail(), ['', '0'], true)) {
            $payload['reply_to'] = [$this->address($message->getReplyToEmail(), $message->getReplyToName())];
        }

        foreach (['cc' => $message->getCC(), 'bcc' => $message->getBCC()] as $field => $copies) {
            if (!\in_array($copies, [null, []], true)) {
                $payload[$field] = array_map(
                    fn (array $copy): array => $this->address($copy['email'], $copy['name'] ?? null),
                    $copies,
                );
            }
        }

        if ($message->getHeaders() !== []) {
            $payload['headers'] = $message->getHeaders();
        }

        if ($attachments !== []) {
            $payload['attachments'] = $attachments;
        }

        return $payload;
    }

    /**
     * Bird takes the display name as a field of its own, so it needs no
     * RFC 5322 quoting.
     *
     * @return array{email: string, name?: string}
     */
    private function address(string $email, ?string $name): array
    {
        if (\in_array($name, [null, '', '0'], true)) {
            return ['email' => $email];
        }

        return ['email' => $email, 'name' => $name];
    }

    /**
     * @return array<array{filename: string, content: string, content_type: string}>
     *
     * @throws \Exception When a file cannot be read or the message exceeds what Bird accepts.
     */
    private function attachments(EmailMessage $message): array
    {
        $files = $message->getAttachments() ?? [];
        $size = \strlen($message->getContent());

        foreach ($files as $file) {
            $bytes = $file->getContent() !== null
                ? \strlen($file->getContent())
                : filesize($file->getPath());

            if ($bytes === false) {
                throw new \Exception('Failed to read attachment file: ' . $file->getPath());
            }

            // What the file takes once it is base64 encoded.
            $size += 4 * intdiv($bytes + 2, 3);
        }

        if ($size > self::MAX_MESSAGE_BYTES) {
            throw new \Exception('Message size exceeds ' . self::MAX_MESSAGE_BYTES . ' bytes: the body and the base64 encoded attachments take ' . $size . ' bytes');
        }

        $attachments = [];

        foreach ($files as $file) {
            $content = $file->getContent() ?? file_get_contents($file->getPath());

            if ($content === false) {
                throw new \Exception('Failed to read attachment file: ' . $file->getPath());
            }

            $attachments[] = [
                'filename' => $file->getName(),
                'content' => base64_encode($content),
                'content_type' => $file->getType(),
            ];
        }

        return $attachments;
    }

    /**
     * The request field Bird's refusal is about, when it names one.
     *
     * @param  array{response: array<string, mixed>|string|null}  $result
     */
    private function field(array $result): ?string
    {
        $envelope = \is_array($result['response']) ? ($result['response']['error'] ?? null) : null;

        return \is_array($envelope) && \is_string($envelope['param'] ?? null) ? $envelope['param'] : null;
    }

    /**
     * Flatten Bird's error envelope into one line: the stable code, the
     * human message, and any per-field detail.
     *
     * @param  array{statusCode: int, response: array<string, mixed>|string|null, error: string|null}  $result
     */
    private function describe(array $result): string
    {
        $envelope = \is_array($result['response']) ? ($result['response']['error'] ?? null) : null;

        if (!\is_array($envelope)) {
            return \in_array($result['error'], [null, ''], true)
                ? "Bird answered with HTTP status {$result['statusCode']}."
                : $result['error'];
        }

        $parts = [];
        if (isset($envelope['code'])) {
            $parts[] = (string) $envelope['code'];
        }
        if (isset($envelope['message'])) {
            $parts[] = (string) $envelope['message'];
        }
        foreach ($envelope['details'] ?? [] as $detail) {
            if (\is_array($detail) && isset($detail['param'], $detail['message'])) {
                $parts[] = "{$detail['param']}: {$detail['message']}";
            }
        }

        return $parts === [] ? "Bird answered with HTTP status {$result['statusCode']}." : implode(' ', $parts);
    }
}
