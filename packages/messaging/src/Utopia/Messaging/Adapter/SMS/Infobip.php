<?php

namespace Utopia\Messaging\Adapter\SMS;

use Utopia\Messaging\Adapter\SMS as SMSAdapter;
use Utopia\Messaging\Messages\SMS as SMSMessage;
use Utopia\Messaging\Response;

// Reference Material
// https://www.infobip.com/docs/api/channels/sms/sms-messaging/outbound-sms/send-sms-message
class Infobip extends SMSAdapter
{
    protected const NAME = 'Infobip';

    /**
     * @param  string  $apiBaseUrl Infobip API Base Url
     * @param  string  $apiKey Infobip API Key
     */
    public function __construct(
        private readonly string $apiBaseUrl,
        private readonly string $apiKey,
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
        return 1000;
    }

    /**
     * {@inheritdoc}
     *
     * @throws \Exception
     */
    protected function process(SMSMessage $message): array
    {
        $to = array_map(fn(string $number): array => ['to' => ltrim($number, '+')], $message->getTo());

        $response = new Response($this->getType());

        $result = $this->request(
            method: 'POST',
            url: "https://{$this->apiBaseUrl}/sms/2/text/advanced",
            headers: [
                'Content-Type: application/json',
                'Authorization: App ' . $this->apiKey,
            ],
            body: [
                'messages' => [
                    'text' => $message->getContent(),
                    'from' => $this->from ?? $message->getFrom(),
                    'destinations' => $to,
                ],
            ],
        );

        if ($result['statusCode'] >= 200 && $result['statusCode'] < 300) {
            $response->setDeliveredTo(\count($message->getTo()));
            foreach ($message->getTo() as $to) {
                $response->addResult($to);
            }
        } else {
            foreach ($message->getTo() as $to) {
                $response->addResult($to, 'Unknown error.');
            }
        }

        return $response->toArray();
    }
}
