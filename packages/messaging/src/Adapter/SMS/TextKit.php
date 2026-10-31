<?php

declare(strict_types=1);

namespace Utopia\Messaging\Adapter\SMS;

use Utopia\Messaging\Adapter\SMS as SMSAdapter;
use Utopia\Messaging\Messages\SMS as SMSMessage;
use Utopia\Messaging\Response;

// Reference Material
// https://jointextkit.com/docs/quickstart
// https://api.jointextkit.com/v1/openapi.yaml
class TextKit extends SMSAdapter
{
    protected const NAME = 'TextKit';

    private const string ENDPOINT = 'https://api.jointextkit.com/v1/messages';

    /**
     * @param  string  $apiKey TextKit API key, `tk_sk_...`.
     * @param  string|null  $from Sender number on the account, in E.164. Falls back to the message sender; TextKit picks the sender itself when the account has only one number.
     */
    public function __construct(
        private readonly string $apiKey,
        private readonly ?string $from = null,
    ) {
        parent::__construct();
    }

    public function getName(): string
    {
        return static::NAME;
    }

    /**
     * The messages endpoint takes one recipient per request.
     */
    public function getMaxMessagesPerRequest(): int
    {
        return 1;
    }

    /**
     * {@inheritdoc}
     *
     * A 2xx means TextKit accepted the message, including a 202 for one it
     * queued or held for the recipient's quiet hours.
     */
    protected function process(SMSMessage $message): array
    {
        $to = '+' . ltrim($message->getTo()[0], '+');
        $response = new Response($this->getType());

        $body = [
            'to' => $to,
            'body' => $message->getContent(),
        ];

        $from = $this->from ?? $message->getFrom();
        if (!\in_array($from, [null, ''], true)) {
            $body['from'] = $from;
        }

        $result = $this->request(
            method: 'POST',
            url: self::ENDPOINT,
            headers: [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            body: $body,
        );

        if ($result['statusCode'] >= 200 && $result['statusCode'] < 300) {
            $response->setDeliveredTo(1);
            $response->addResult($to);

            return $response->toArray();
        }

        $response->addResult($to, $this->describe($result));

        return $response->toArray();
    }

    /**
     * Flatten TextKit's error envelope into one line: the stable code and
     * the human message.
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

        return $parts === [] ? 'Unknown error.' : implode(': ', $parts);
    }
}
