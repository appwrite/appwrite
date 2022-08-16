<?php

namespace Appwrite\SMS\Adapter;

use Appwrite\SMS\Adapter;

// Reference Material
// https://www.twilio.com/docs/sms/api

class Twilio extends Adapter
{
    /**
     * @var string
     */
    private string $endpoint = 'https://api.twilio.com/2010-04-01';

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
            url: "{$this->endpoint}/Accounts/{$this->user}/Messages.json",
            payload: \http_build_query([
                'Body' => $message,
                'From' => $from,
                'To' => $to
            ]),
            userpwd: "{$this->user}:{$this->secret}"
        );
    }
}
