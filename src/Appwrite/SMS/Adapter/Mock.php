<?php

namespace Appwrite\SMS\Adapter;

use Appwrite\SMS\Adapter;

// Mock adapter used to E2E test worker
class Mock extends Adapter
{
    /**
     * @var string
     */
    private string $endpoint = 'http://request-catcher:5000/mock-sms';

    /**
     * @param string $from
     * @param string $to
     * @param string $message
     * @return void
     */
    public function send(string $from, string $to, string $message): void
    {
        $this->request(
            method: 'POST',
            url: $this->endpoint,
            payload: \json_encode([
                'message' => $message,
                'from' => $from,
                'to' => $to
            ]),
            headers: [
                "content-type: application/json",
                "x-username: {$this->user}",
                "x-key: {$this->secret}",
            ]
        );
    }
}
