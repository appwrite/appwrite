<?php

namespace Utopia\Messaging\Adapter\SMS;

use Utopia\Messaging\Adapter\SMS as SMSAdapter;
use Utopia\Messaging\Messages\SMS as SMSMessage;
use Utopia\Messaging\Response;

class Telnyx extends SMSAdapter
{
    protected const NAME = 'Telnyx';

    /**
     * @param  string  $apiKey Telnyx APIv2 Key
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

    public function getMaxMessagesPerRequest(): int
    {
        return 1;
    }

    /**
     * {@inheritdoc}
     *
     * @throws \Exception
     */
    protected function process(SMSMessage $message): array
    {
        $response = new Response($this->getType());

        $result = $this->request(
            method: 'POST',
            url: 'https://api.telnyx.com/v2/messages',
            headers: [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            body: [
                'text' => $message->getContent(),
                'from' => $this->from ?? $message->getFrom(),
                'to' => $message->getTo()[0],
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
