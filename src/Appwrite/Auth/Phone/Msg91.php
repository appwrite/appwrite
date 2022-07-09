<?php

namespace Appwrite\Auth\Phone;

use Appwrite\Auth\Phone;

// Reference Material
// https://docs.msg91.com/p/tf9GTextN/e/Irz7-x1PK/MSG91

class Msg91 extends Phone
{
    /**
     * @var string
     */
    private string $endpoint = 'https://api.msg91.com/api/v5/flow/';

    /**
     * For Flow based sending SMS sender ID should not be set in flow
     * In environment _APP_PHONE_PROVIDER format is 'phone://[senderID]:[authKey]@msg91'.
     * _APP_PHONE_FROM value is flow ID created in Msg91
     * Eg. _APP_PHONE_PROVIDER = phone://DINESH:5e1e93cad6fc054d8e759a5b@msg91
     * _APP_PHONE_FROM = 3968636f704b303135323339
     * @param string $from-> utilized from for flow id
     * @param string $to
     * @param string $message
     * @return void
     */
    public function send(string $from, string $to, string $message): void
    {
        $to = ltrim($to, '+');
        $this->request(
            method: 'POST',
            url: $this->endpoint,
            payload: json_encode([
                'sender' => $this->user,
                'otp' => $message,
                'flow_id' => $from,
                'mobiles' => $to
            ]),
            headers: [
                "content-type: application/JSON",
                "authkey: {$this->secret}",
            ]
        );
    }
}
