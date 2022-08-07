<?php

namespace Appwrite\Auth\Phone;

use Appwrite\Auth\Phone;

// Reference Material
// https://docs.fast2sms.com/?php#overview

class Fast2SMS extends Phone
{
    /**
     * @var string
     */
    private string $endpoint = "https://www.fast2sms.com/dev/bulkV2";

    /**
     * _APP_PHONE_FROM value contains "senderId" from fast2sms which will be FSTSMS by defualt and DLT-ID for DLT enabled users and user API Key.
     * _APP_PHONE_PROVIDER = phone://[FSTSMS (for normal users) or YOUR_DLT ID(for dlt enabled user)]:[YOUR_API_KEY]@fast2sms
     * _APP_PHONE_FROM = 0 (for normal users) or 111134 (for dlt enabled user)
     * Example: 
     * _APP_PHONE_PROVIDER = phone://FSTSMS:2aTfMz94QtpANxoVj0dsdsrddjmbwU3JFB6dDi1srKZmESPCDSDBSDSDuUCpLPSA1ZVY5zvmhq8H@fast2sms
     * _APP_PHONE_FROM = 0
     * 
     * @param string $from-> utilized from for flow id
     * @param string $to
     * @param string $message
     * @return void
     */
    public function send(string $from, string $to, string $message): void
    {
        $to = ltrim($to, '+');
        $from = ltrim($from, '+');
        $headers = ["authorization: $secret","accept: */*","cache-control: no-cache","content-type: application/json"];

        if ($user == 'FSTSMS') {
          $this->request(
            method: 'POST',
            url: $this->endpoint,
            headers: $headers,
            payload: \http_build_query([
                "variables_values" => $message,
                "route" => "otp",
                "numbers" => $to,
            ])
        );
      } else {
        $this->request(
            method: 'POST',
            url: $this->endpoint,
            headers: $headers,
            payload: \http_build_query([
                "sender_id" => $user,
                "message" => $from,
                "variables_values" => $message,
                "route" => "dlt",
                "numbers" => $to,
            ])
        );
      } 
    }
}
