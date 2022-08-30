<?php

namespace Appwrite\SMS\Adapter;

use Appwrite\SMS\Adapter;

// Reference Material
// https://developer.vonage.com/api/sms

class Vonage extends Adapter
{
    /**
     * @var string
     */
    private string $endpoint = 'https://rest.nexmo.com/sms/json';

    /**
     * @param string $from
     * @param string $to
     * @param string $message
     * @return void
     */
    public function send(string $from, string $to, string $message): void
    {
        $to = ltrim($to, '+');
        $headers = ['Content-Type: application/x-www-form-urlencoded'];

        $this->request(
            method: 'POST',
            url: $this->endpoint,
            headers: $headers,
            payload: \http_build_query([
                'text' => $message,
                'from' => $from,
                'to' => $to,
                'api_key' => $this->user,
                'api_secret' => $this->secret
            ])
        );
    }
}
