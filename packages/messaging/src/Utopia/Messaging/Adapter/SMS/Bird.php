<?php

declare(strict_types=1);

namespace Utopia\Messaging\Adapter\SMS;

use InvalidArgumentException;
use Utopia\Messaging\Adapter\SMS as SMSAdapter;
use Utopia\Messaging\Adapter\SMS\Bird\Category;
use Utopia\Messaging\Messages\SMS as SMSMessage;
use Utopia\Messaging\Response;

// Reference Material
// https://bird.com/docs/guides/sms/sending-sms
// https://bird.com/docs/api/authentication
// https://bird.com/docs/api/errors
class Bird extends SMSAdapter
{
    protected const NAME = 'Bird';

    /**
     * Bird keys are shaped `bk_{region}_{payload}{checksum}`; the region
     * selects the host, and a key sent elsewhere is refused with a 421.
     */
    private const string KEY_PATTERN = '/^bk_([a-z]{2}\d+)_/';

    private const string HOST_PATTERN = 'https://%s.platform.bird.com';

    private const string PATH_MESSAGE = '/v1/sms/messages';

    private const string PATH_BATCH = '/v1/sms/batches';

    /**
     * Bird accepts up to 100 messages per batch request.
     */
    private const int MAX_BATCH_SIZE = 100;

    private readonly string $endpoint;

    /**
     * @param  string  $apiKey Workspace API key, `bk_{region}_...`, with the SMS send scope.
     * @param  string|null  $from Sender the workspace holds: an owned E.164 number, a short code, or a claimed alphanumeric sender ID. Falls back to the message sender.
     * @param  Category  $category Why the message is sent; Bird requires it on every free-text send.
     * @param  string|null  $endpoint Overrides the regional host derived from the key, for tests and proxies.
     *
     * @throws InvalidArgumentException When the key carries no region and no endpoint is given.
     */
    public function __construct(
        private readonly string $apiKey,
        private readonly ?string $from = null,
        private readonly Category $category = Category::TRANSACTIONAL,
        ?string $endpoint = null,
    ) {
        $this->endpoint = rtrim($endpoint ?? self::hostFor($apiKey), '/');

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
     * Regional host a key belongs to.
     *
     * @throws InvalidArgumentException When the key does not start with `bk_{region}_`.
     */
    public static function hostFor(string $apiKey): string
    {
        if (preg_match(self::KEY_PATTERN, $apiKey, $matches) !== 1) {
            throw new InvalidArgumentException('Bird API key must start with bk_{region}_, for example bk_eu1_ or bk_us1_.');
        }

        return \sprintf(self::HOST_PATTERN, $matches[1]);
    }

    /**
     * {@inheritdoc}
     *
     * One recipient goes through the single-message endpoint; several go
     * through a batch, where Bird validates every message before queuing
     * any, so the whole request either succeeds or fails.
     */
    protected function process(SMSMessage $message): array
    {
        $recipients = array_map($this->normalize(...), $message->getTo());
        $response = new Response($this->getType());

        $payloads = array_map(fn(string $to): array => $this->payload($message, $to), $recipients);

        $result = \count($payloads) === 1
            ? $this->request(
                method: 'POST',
                url: $this->endpoint . self::PATH_MESSAGE,
                headers: $this->headers(),
                body: $payloads[0],
            )
            : $this->request(
                method: 'POST',
                url: $this->endpoint . self::PATH_BATCH,
                headers: $this->headers(),
                body: ['messages' => $payloads],
            );

        if ($result['statusCode'] >= 200 && $result['statusCode'] < 300) {
            $response->setDeliveredTo(\count($recipients));
            foreach ($recipients as $to) {
                $response->addResult($to);
            }

            return $response->toArray();
        }

        $error = $this->describe($result);
        foreach ($recipients as $to) {
            $response->addResult($to, $error);
        }

        return $response->toArray();
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
     * @return array<string, mixed>
     */
    private function payload(SMSMessage $message, string $to): array
    {
        $payload = [
            'to' => $to,
            'from' => $this->from ?? $message->getFrom(),
            'text' => $message->getContent(),
            'category' => $this->category->value,
        ];

        // Bird echoes metadata on every delivery event, so callers can
        // correlate webhooks with their own records.
        if (!\in_array($message->getMetadata(), [null, []], true)) {
            $payload['metadata'] = $message->getMetadata();
        }

        return $payload;
    }

    /**
     * Bird wants E.164 with the leading plus and stores the canonical form.
     */
    private function normalize(string $to): string
    {
        return '+' . ltrim($to, '+');
    }

    /**
     * Flatten Bird's error envelope into one line: the stable code, the
     * human message, and any per-field detail.
     *
     * @param  array{statusCode: int, response: array<string, mixed>|string|null, error: string}  $result
     */
    private function describe(array $result): string
    {
        $envelope = \is_array($result['response']) ? ($result['response']['error'] ?? null) : null;

        if (!\is_array($envelope)) {
            return $result['error'] !== '' ? $result['error'] : 'Unknown error.';
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

        return $parts === [] ? 'Unknown error.' : implode(' ', $parts);
    }
}
