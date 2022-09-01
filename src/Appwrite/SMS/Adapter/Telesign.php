<?php

namespace Appwrite\SMS\Adapter;

use Appwrite\SMS\Adapter;

// Reference Material
// https://developer.telesign.com/enterprise/docs/sms-api-send-an-sms

class Telesign extends Adapter
{
    /**
     * @var string
     */
    private string $endpoint = 'https://rest-api.telesign.com/v1/messaging';

    /**
     * @param string $from
     * @param string $to
     * @param string $message
     * @return void
     * @throws \Appwrite\Extend\Exception
     */
    public function send(string $from, string $to, string $message): void
    {
        $to = ltrim($to, '+');

        $this->request(
            method: 'POST',
            url: $this->endpoint,
            payload: \http_build_query([
                'message' => $message,
                'message_type' => 'otp',
                'phone_number' => $to
            ]),
            userpwd: "{$this->user}:{$this->secret}"
        );
    }
}
