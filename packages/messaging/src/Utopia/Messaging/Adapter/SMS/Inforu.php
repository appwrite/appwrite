<?php

namespace Utopia\Messaging\Adapter\SMS;

use Utopia\Messaging\Adapter\SMS as SMSAdapter;
use Utopia\Messaging\Messages\SMS as SMSMessage;
use Utopia\Messaging\Response;

// Reference Material
// https://apidoc.inforu.co.il/#d7b3f69d-7422-44b4-b7d1-0959f8a08881
class Inforu extends SMSAdapter
{
    protected const NAME = 'Inforu';

    /**
     * @param string $apiToken Inforu API token
     * @param string $senderId Sender ID
     */
    public function __construct(
        private readonly string $senderId,
        private readonly string $apiToken,
    ) {
        parent::__construct();
    }

    public function getName(): string
    {
        return static::NAME;
    }

    public function getMaxMessagesPerRequest(): int
    {
        return 100; // Setting a conservative limit, adjust if needed
    }

    /**
     * {@inheritdoc}
     *
     * @throws \Exception
     */
    protected function process(SMSMessage $message): array
    {
        $response = new Response($this->getType());

        $recipients = array_map(
            fn(string $number): array => ['Phone' => ltrim($number, '+')],
            $message->getTo(),
        );

        $result = $this->request(
            method: 'POST',
            url: 'https://capi.inforu.co.il/api/v2/SMS/SendSms',
            headers: [
                'Content-Type: application/json',
                'Authorization: Basic ' . $this->apiToken,
            ],
            body: [
                'Data' => [
                    'Message' => $message->getContent(),
                    'Recipients' => $recipients,
                    'Settings' => [
                        'Sender' => $this->senderId,
                    ],
                ],
            ],
        );

        if ($result['statusCode'] === 200 && ($result['response']['StatusId'] ?? 0) === 1) {
            $response->setDeliveredTo(\count($message->getTo()));
            foreach ($message->getTo() as $to) {
                $response->addResult($to);
            }
        } else {
            $errorMessage = $result['response']['StatusDescription'] ?? 'Unknown error';
            foreach ($message->getTo() as $to) {
                $response->addResult($to, $errorMessage);
            }
        }

        return $response->toArray();
    }
}
