<?php

namespace Appwrite\Messaging\Adapter\SMS;

use Utopia\Messaging\Adapter\SMS as SMSAdapter;
use Utopia\Messaging\Messages\SMS;
use Utopia\Messaging\Response;

/**
 * Vonage Messages API SMS Adapter
 *
 * Uses the Vonage Messages REST API (https://api.nexmo.com/v1/messages)
 * instead of the legacy Nexmo SMS API used by the existing Vonage adapter.
 * The Messages API supports richer channel options and is the recommended
 * path for new integrations.
 *
 * Authentication: HTTP Basic Auth using API key and secret.
 *
 * @see https://developer.vonage.com/en/api/messages-olympus
 */
class VonageMessages extends SMSAdapter
{
    protected const NAME = 'VonageMessages';

    /**
     * @param string $apiKey    Vonage API Key
     * @param string $apiSecret Vonage API Secret
     */
    public function __construct(
        private string $apiKey,
        private string $apiSecret,
    ) {
    }

    public function getName(): string
    {
        return static::NAME;
    }

    public function getMaxMessagesPerRequest(): int
    {
        return 1;
    }

    /**
     * {@inheritdoc}
     */
    protected function process(SMS $message): array
    {
        $response = new Response($this->getType());

        $to = \array_map(
            fn ($to) => \ltrim($to, '+'),
            $message->getTo()
        );

        $result = $this->request(
            method: 'POST',
            url: 'https://api.nexmo.com/v1/messages',
            headers: [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
                'Authorization' => 'Basic ' . \base64_encode($this->apiKey . ':' . $this->apiSecret),
            ],
            body: \json_encode([
                'message_type' => 'text',
                'channel'      => 'sms',
                'to'           => $to[0],
                'from'         => \ltrim($message->getFrom(), '+'),
                'text'         => $message->getContent(),
            ]),
        );

        $statusCode = $result['statusCode'] ?? 0;

        if ($statusCode >= 200 && $statusCode < 300) {
            $response->setDeliveredTo(1);
            $response->addResult($message->getTo()[0]);
        } else {
            $body = $result['response'] ?? [];
            $errorTitle  = $body['title']       ?? $body['error-text'] ?? 'Unknown error';
            $errorDetail = $body['detail']      ?? '';
            $error = $errorDetail !== '' ? "{$errorTitle}: {$errorDetail}" : $errorTitle;

            $response->addResult($message->getTo()[0], $error);
        }

        return $response->toArray();
    }
}
