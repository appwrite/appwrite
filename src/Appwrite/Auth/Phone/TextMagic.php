<?php

namespace Appwrite\Auth\Phone;

use Appwrite\Auth\Phone;

// Reference Material
// https://www.textmagic.com/docs/api/start/

class TextMagic extends Phone
{
    /**
     * @var string
     */
    private string $endpoint = 'https://rest.textmagic.com/api/v2';

    /**
     * @param string $from
     * @param string $to
     * @param string $message
     * @return void
     */
    public function send(string $from, string $to, string $message): void
    {
        $to = ltrim($to, '+');
        $from = ltrim($from, '+');

        $this->request(
            method: 'POST',
            url: $this->endpoint . '/messages',
            payload: \http_build_query([
                'text' => $message,
                'from' => $from,
                'phones' => $to
            ]),
            headers: [
                "X-TM-Username: {$this->user}",
                "X-TM-Key: {$this->secret}",
            ]
        );
    }
}
