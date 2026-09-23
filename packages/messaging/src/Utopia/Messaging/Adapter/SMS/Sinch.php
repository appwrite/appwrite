<?php

namespace Utopia\Messaging\Adapter\SMS;

use Utopia\Messaging\Adapter\SMS as SMSAdapter;
use Utopia\Messaging\Messages\SMS as SMSMessage;
use Utopia\Messaging\Response;

// Reference Material
// https://developers.sinch.com/docs/sms/api-reference/
class Sinch extends SMSAdapter
{
    protected const NAME = 'Sinch';

    /**
     * @param  string  $servicePlanId Sinch Service plan ID
     * @param  string  $apiToken Sinch API token
     */
    public function __construct(
        private readonly string $servicePlanId,
        private readonly string $apiToken,
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
        $to = array_map(fn(string $number): string => ltrim($number, '+'), $message->getTo());

        $response = new Response($this->getType());

        $result = $this->request(
            method: 'POST',
            url: "https://sms.api.sinch.com/xms/v1/{$this->servicePlanId}/batches",
            headers: [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiToken,
            ],
            body: [
                'from' => $this->from ?? $message->getFrom(),
                'to' => $to,
                'body' => $message->getContent(),
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
