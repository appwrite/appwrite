<?php

namespace Utopia\Messaging\Adapter\SMS;

use Utopia\Messaging\Adapter\SMS as SMSAdapter;
use Utopia\Messaging\Messages\SMS as SMSMessage;
use Utopia\Messaging\Response;

class Twilio extends SMSAdapter
{
    protected const NAME = 'Twilio';

    /**
     * @param  string  $accountSid Twilio Account SID
     * @param  string  $authToken Twilio Auth Token
     */
    public function __construct(
        private readonly string $accountSid,
        private readonly string $authToken,
        private readonly ?string $from = null,
        private readonly ?string $messagingServiceSid = null,
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
    protected function process(SMSMessage $message): array
    {
        $response = new Response($this->getType());

        $result = $this->request(
            method: 'POST',
            url: "https://api.twilio.com/2010-04-01/Accounts/{$this->accountSid}/Messages.json",
            headers: [
                'Content-Type: application/x-www-form-urlencoded',
                'Authorization: Basic ' . base64_encode("{$this->accountSid}:{$this->authToken}"),
            ],
            body: [
                'Body' => $message->getContent(),
                'From' => $this->from ?? $message->getFrom(),
                'MessagingServiceSid' => $this->messagingServiceSid ?? null,
                'To' => $message->getTo()[0],
            ],
        );

        if ($result['statusCode'] >= 200 && $result['statusCode'] < 300) {
            $response->setDeliveredTo(1);
            $response->addResult($message->getTo()[0]);
        } elseif (!\is_null($result['response']['message'] ?? null)) {
            $response->addResult($message->getTo()[0], $result['response']['message']);
        } else {
            $response->addResult($message->getTo()[0], 'Unknown error');
        }

        return $response->toArray();
    }
}
