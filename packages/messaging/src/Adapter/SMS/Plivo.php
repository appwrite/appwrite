<?php

namespace Utopia\Messaging\Adapter\SMS;

use Utopia\Messaging\Adapter\SMS as SMSAdapter;
use Utopia\Messaging\Messages\SMS as SMSMessage;
use Utopia\Messaging\Response;

// Reference Material
// https://www.plivo.com/docs/sms/api/message#send-a-message
class Plivo extends SMSAdapter
{
    protected const NAME = 'Plivo';

    /**
     * @param  string  $authId Plivo Auth ID
     * @param  string  $authToken Plivo Auth Token
     */
    public function __construct(
        private readonly string $authId,
        private readonly string $authToken,
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
        $response = new Response($this->getType());

        $result = $this->request(
            method: 'POST',
            url: "https://api.plivo.com/v1/Account/{$this->authId}/Message/",
            headers: [
                'Content-Type: application/x-www-form-urlencoded',
                'Authorization: Basic ' . base64_encode("{$this->authId}:{$this->authToken}"),
            ],
            body: [
                'text' => $message->getContent(),
                'src' => $this->from ?? $message->getFrom() ?? 'Plivo',
                'dst' => implode('<', $message->getTo()),
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
