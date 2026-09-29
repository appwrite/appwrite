<?php

namespace Appwrite\Utopia\Messaging\Adapter;

use Appwrite\Utopia\Messaging\Messages\Webhook as WebhookMessage;
use Psr\Http\Client\ClientExceptionInterface;
use Utopia\Client\Adapter\Curl\Client as CurlAdapter;
use Utopia\Client\Client;
use Utopia\Messaging\Adapter;
use Utopia\Messaging\Message;
use Utopia\Messaging\Response;
use Utopia\Psr7\ContentType;
use Utopia\Psr7\Header;
use Utopia\Psr7\Request\Factory as RequestFactory;

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
        $client = (new Client(new CurlAdapter()))
            ->withTimeout($timeout)
            ->withConnectTimeout(\min(10, $timeout))
            ->withHeaders([Header::USER_AGENT => 'Appwrite Webhook']);

        try {
            $request = (new RequestFactory())->body($method, $url, $body, ContentType::JSON);
            foreach ($headers as $header) {
                $parts = \explode(':', $header, 2);
                if (\count($parts) === 2) {
                    $request = $request->withHeader(\trim($parts[0]), \trim($parts[1]));
                }
            }

            $response = $client->sendRequest($request);
        } catch (ClientExceptionInterface|\InvalidArgumentException $exception) {
            // A malformed URL or header fails this delivery alone, like a transport error
            return [
                'statusCode' => 0,
                'response' => null,
                'error' => $exception->getMessage(),
            ];
        }

        return [
            'statusCode' => $response->getStatusCode(),
            'response' => (string) $response->getBody(),
            'error' => null,
        ];
    }
}
