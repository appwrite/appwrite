<?php

namespace Utopia\Messaging\Adapter\Push;

use Utopia\Messaging\Adapter\Push as PushAdapter;
use Utopia\Messaging\Helpers\JWT;
use Utopia\Messaging\Messages\Push as PushMessage;
use Utopia\Messaging\Priority;
use Utopia\Messaging\Response;

class APNS extends PushAdapter
{
    protected const NAME = 'APNS';

    public function __construct(
        private readonly string $authKey,
        private readonly string $authKeyId,
        private readonly string $teamId,
        private readonly string $bundleId,
        private readonly bool $sandbox = false,
    ) {
        parent::__construct();
    }

    /**
     * Get adapter name.
     */
    public function getName(): string
    {
        return static::NAME;
    }

    /**
     * Get max messages per request.
     */
    public function getMaxMessagesPerRequest(): int
    {
        return 5000;
    }

    /**
     * {@inheritdoc}
     */
    protected function process(PushMessage $message): array
    {
        $payload = [];

        if (!\is_null($message->getTitle())) {
            $payload['aps']['alert']['title'] = $message->getTitle();
        }
        if (!\is_null($message->getBody())) {
            $payload['aps']['alert']['body'] = $message->getBody();
        }
        if (!\is_null($message->getData())) {
            $payload['aps']['data'] = $message->getData();
        }
        if (!\is_null($message->getAction())) {
            $payload['aps']['category'] = $message->getAction();
        }
        if (!\is_null($message->getCritical())) {
            $payload['aps']['sound']['critical'] = 1;
            $payload['aps']['sound']['name'] = 'default';
            $payload['aps']['sound']['volume'] = 1.0;
        }
        if (!\is_null($message->getSound())) {
            if (!\is_null($message->getCritical())) {
                $payload['aps']['sound']['name'] = $message->getSound();
            } else {
                $payload['aps']['sound'] = $message->getSound();
            }
        }
        if (!\is_null($message->getBadge())) {
            $payload['aps']['badge'] = $message->getBadge();
        }
        if (!\is_null($message->getContentAvailable())) {
            $payload['aps']['content-available'] = (int) $message->getContentAvailable();
        }

        $priority = null;
        if (!\is_null($message->getPriority())) {
            $priority = match ($message->getPriority()) {
                Priority::HIGH => '10',
                Priority::NORMAL => '5',
            };
        }

        // A content-available push with no alert is a background notification. Apple requires it to be
        // sent with the `background` push type and priority 5 — it rejects priority 10 for background —
        // so a silent wake reaches a suspended app rather than being dropped.
        $background = \is_null($message->getTitle())
            && \is_null($message->getBody())
            && (int) $message->getContentAvailable() === 1;
        $pushType = $background ? 'background' : 'alert';
        if ($background) {
            $priority = '5';
        }

        $claims = [
            'iss' => $this->teamId,   // Issuer
            'iat' => time(),         // Issued at time
            'exp' => time() + 3600,  // Expiration time
        ];

        $jwt = JWT::encode(
            $claims,
            $this->authKey,
            'ES256',
            $this->authKeyId,
        );

        $endpoint = 'https://api.push.apple.com';

        if ($this->sandbox) {
            $endpoint = 'https://api.development.push.apple.com';
        }

        $urls = [];
        foreach ($message->getTo() as $token) {
            $urls[] = $endpoint . '/3/device/' . $token;
        }

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $jwt,
            'apns-topic: ' . $this->bundleId,
            'apns-push-type: ' . $pushType,
        ];
        if (!\is_null($priority)) {
            $headers[] = 'apns-priority: ' . $priority;
        }

        $results = $this->requestMulti(
            method: 'POST',
            urls: $urls,
            headers: $headers,
            bodies: [$payload],
        );

        $response = new Response($this->getType());

        foreach ($results as $result) {
            $device = basename($result['url']);
            $statusCode = $result['statusCode'];

            switch ($statusCode) {
                case 200:
                    $response->incrementDeliveredTo();
                    $response->addResult($device);
                    break;
                default:
                    $error = ($result['response']['reason'] ?? null) === 'ExpiredToken' || ($result['response']['reason'] ?? null) === 'BadDeviceToken'
                        ? $this->getExpiredErrorMessage()
                        : ($result['response']['reason'] ?? ($result['error'] ?: 'Unknown error'));

                    $response->addResult($device, $error);
                    break;
            }
        }

        return $response->toArray();
    }
}
