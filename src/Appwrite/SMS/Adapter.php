<?php

namespace Appwrite\SMS;

abstract class Adapter
{
    /**
     * @var string
     */
    protected string $user;

    /**
     * @var string
     */
    protected string $secret;

    /**
     * @param string $key
     */
    public function __construct(string $user, string $secret)
    {
        $this->user = $user;
        $this->secret = $secret;
    }

    /**
     * Send Message to phone.
     * @param string $from
     * @param string $to
     * @param string $message
     * @return void
     */
    abstract public function send(string $from, string $to, string $message): void;

    /**
     * @param string $method
     * @param string $url
     * @param array  $headers
     * @param string $payload
     *
     * @return string
     */
    protected function request(string $method, string $url, array $headers = [], ?string $payload = null, ?string $userpwd = null): string
    {
        $ch = \curl_init($url);

        \curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        \curl_setopt($ch, CURLOPT_HEADER, 0);
        \curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        \curl_setopt($ch, CURLOPT_USERAGENT, 'Appwrite Phone Authentication');

        if (!is_null($payload)) {
            \curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        if (!is_null($userpwd)) {
            \curl_setopt($ch, CURLOPT_USERPWD, $userpwd);
        }

        $headers[] = 'Content-length: ' . \strlen($payload);

        \curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = (string) \curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        \curl_close($ch);

        if ($code >= 400) {
            throw new \Exception($response);
        }

        return $response;
    }
}
