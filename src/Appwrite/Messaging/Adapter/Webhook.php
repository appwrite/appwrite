<?php

namespace Appwrite\Messaging\Adapter;

use Appwrite\Messaging\Messages\Webhook as WebhookMessage;
use Utopia\Messaging\Adapter;
use Utopia\Messaging\Message;
use Utopia\Messaging\Response;

class Webhook extends Adapter
{
    protected const NAME = 'Webhook';
    protected const TYPE = 'webhook';
    protected const MESSAGE_TYPE = WebhookMessage::class;

    protected const SIGNATURE_HEADER = 'X-Appwrite-Webhook-Signature';
    protected const TIMESTAMP_HEADER = 'X-Appwrite-Webhook-Timestamp';

    public function getName(): string
    {
        return static::NAME;
    }

    public function getType(): string
    {
        return static::TYPE;
    }

    public function getMessageType(): string
    {
        return static::MESSAGE_TYPE;
    }

    public function getMaxMessagesPerRequest(): int
    {
        return 100;
    }

    public function send(Message $message): array
    {
        if (!$message instanceof WebhookMessage) {
            throw new \Exception('Invalid message type.');
        }

        return $this->process($message);
    }

    protected function process(WebhookMessage $message): array
    {
        $response = new Response($this->getType());
        $body = \json_encode($message->getPayload(), JSON_THROW_ON_ERROR);
        $timestamp = (string) \time();

        $headers = [
            'Content-Type: application/json',
            self::TIMESTAMP_HEADER . ': ' . $timestamp,
        ];

        $secret = $message->getSigningSecret();
        if ($secret !== null && $secret !== '') {
            $signature = \hash_hmac('sha256', $timestamp . '.' . $body, $secret);
            $headers[] = self::SIGNATURE_HEADER . ': sha256=' . $signature;
        }

        foreach ($message->getHeaders() as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }

        $delivered = 0;
        foreach ($message->getUrls() as $url) {
            $result = $this->dispatch('POST', $url, $headers, $body, $message->getTimeout());
            if ($result['statusCode'] >= 200 && $result['statusCode'] < 300 && empty($result['error'])) {
                $delivered++;
                $response->addResult($url);
            } else {
                $response->addResult($url, $result['error'] ?: ('HTTP ' . $result['statusCode']));
            }
        }

        $response->setDeliveredTo($delivered);
        return $response->toArray();
    }

    /**
     * @param array<int, string> $headers
     * @return array{statusCode: int, response: string|null, error: string|null}
     */
    protected function dispatch(string $method, string $url, array $headers, string $body, int $timeout): array
    {
        $handle = \curl_init();
        \curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_URL => $url,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => \min(10, $timeout),
            CURLOPT_USERAGENT => 'Appwrite Webhook',
        ]);

        $output = \curl_exec($handle);
        $statusCode = (int) \curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = \curl_error($handle);
        \curl_close($handle);

        return [
            'statusCode' => $statusCode,
            'response' => \is_string($output) ? $output : null,
            'error' => $error !== '' ? $error : null,
        ];
    }
}
