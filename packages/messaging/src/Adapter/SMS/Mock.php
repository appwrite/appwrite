<?php

namespace Utopia\Messaging\Adapter\SMS;

use Utopia\Messaging\Adapter\SMS as SMSAdapter;
use Utopia\Messaging\Messages\SMS as SMSMessage;
use Utopia\Messaging\Response;

class Mock extends SMSAdapter
{
    protected const NAME = 'Mock';

    protected string $url = 'http://request-catcher:5000/mock-sms';

    /**
     * @param  string  $user User ID
     * @param  string  $secret User secret
     */
    public function __construct(
        private readonly string $user,
        private readonly string $secret,
    ) {
        parent::__construct();
    }

    public function getName(): string
    {
        return static::NAME;
    }

    public function getEndpoint(): string
    {
        return $this->url;
    }

    public function setEndpoint(string $url): self
    {
        $this->url = $url;
        return $this;
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

        $response->setDeliveredTo(\count($message->getTo()));

        $result = $this->request(
            method: 'POST',
            url: $this->url,
            headers: [
                'Content-Type: application/json',
                "X-Username: {$this->user}",
                "X-Key: {$this->secret}",
            ],
            body: [
                'message' => $message->getContent(),
                'from' => $message->getFrom(),
                'to' => implode(',', $message->getTo()),
            ],
        );

        if ($result['statusCode'] === 200) {
            $response->setDeliveredTo(\count($message->getTo()));
            foreach ($message->getTo() as $to) {
                $response->addResult($to);
            }
        } else {
            foreach ($message->getTo() as $to) {
                $response->addResult($to, 'Unknown Error.');
            }
        }

        return $response->toArray();
    }
}
