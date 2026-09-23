<?php

namespace Utopia\Messaging\Adapter\SMS;

// Reference Material
// https://www.textmagic.com/docs/api/send-sms/#How-to-send-bulk-text-messages

use Utopia\Messaging\Adapter\SMS as SMSAdapter;
use Utopia\Messaging\Messages\SMS;
use Utopia\Messaging\Response;

class Vonage extends SMSAdapter
{
    protected const NAME = 'Vonage';

    /**
     * @param  string  $apiKey Vonage API Key
     * @param  string  $apiSecret Vonage API Secret
     */
    public function __construct(
        private readonly string $apiKey,
        private readonly string $apiSecret,
        private readonly ?string $from = null,
    ) {
        parent::__construct();
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
        $to = array_map(
            fn(string $to): string => ltrim($to, '+'),
            $message->getTo(),
        );

        $response = new Response($this->getType());
        $result = $this->request(
            method: 'POST',
            url: 'https://rest.nexmo.com/sms/json',
            headers: [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            body: [
                'text' => $message->getContent(),
                'from' => $this->from ?? $message->getFrom(),
                'to' => $to[0], //\implode(',', $to),
                'api_key' => $this->apiKey,
                'api_secret' => $this->apiSecret,
            ],
        );

        if (($result['response']['messages'][0]['status'] ?? null) === 0) {
            $response->setDeliveredTo(1);
            $response->addResult($result['response']['messages'][0]['to']);
        } elseif (!\is_null($result['response']['messages'][0]['error-text'] ?? null)) {
            $response->addResult($message->getTo()[0], $result['response']['messages'][0]['error-text']);
        } else {
            $response->addResult($message->getTo()[0], 'Unknown error');
        }

        return $response->toArray();
    }
}
