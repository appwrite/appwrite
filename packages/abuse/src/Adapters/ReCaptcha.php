<?php

namespace Utopia\Abuse\Adapters;

use Exception;
use Utopia\Abuse\Adapter;

class ReCaptcha extends Adapter
{
    /**
     * Use this for communication between your site and Google.
     * Be sure to keep it a secret.
     *
     * @var string
     */
    protected string $secret = '';

    /**
     * The value of 'g-recaptcha-response'.
     *
     * @var string
     */
    protected string $response = '';

    /**
     * The end user's ip address
     *
     * @var string
     */
    protected string $remoteIP = '';

    /**
     * ReCaptcha Adapter
     *
     * See more information about the implementation instructions
     *
     * @see https://developers.google.com/recaptcha/docs/verify
     *
     * Admin Panel
     * @see https://www.google.com/recaptcha/admin
     *
     * @param  string  $secret
     * @param  string  $response
     * @param  string  $remoteIP
     */
    public function __construct(string $secret, string $response, string $remoteIP)
    {
        $this->secret = $secret;
        $this->response = $response;
        $this->remoteIP = $remoteIP;
    }

    /**
     * Check
     *
     * Check if user is human or not, compared to score
     *
     * @param  float  $score
     * @return bool
     */
    public function check(float $score = 0.5): bool
    {
        $url = 'https://www.google.com/recaptcha/api/siteverify';
        $fields = [
            'secret' => \urlencode($this->secret),
            'response' => \urlencode($this->response),
            'remoteip' => \urlencode($this->remoteIP),
        ];

        //open connection
        $ch = \curl_init();

        //set the url, number of POST vars, POST data
        \curl_setopt($ch, CURLOPT_URL, $url);
        \curl_setopt($ch, CURLOPT_POST, \count($fields));
        \curl_setopt($ch, CURLOPT_POSTFIELDS, \http_build_query($fields));
        \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        //execute post
        /** @var array<string, mixed> $result */
        $result = \json_decode((string) \curl_exec($ch), true);

        if ($result['success'] && $result['score'] >= $score) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Delete logs older than $timestamp
     *
     * @param  int  $timestamp
     * @return bool
     *
     * @throws Exception
     */
    public function cleanup(int $timestamp): bool
    {
        throw new Exception('Method not supported');
    }

    /**
     * Get abuse logs
     *
     * Return logs with an offset and limit
     *
     * @param  int|null  $offset
     * @param  int|null  $limit
     * @return array<string, mixed>
     *
     * @throws Exception
     */
    public function getLogs(?int $offset = null, ?int $limit = 25): array
    {
        throw new Exception('Method not supported');
    }

    /**
     * Reset
     *
     * Reset is not applicable for ReCaptcha adapter
     *
     * @return void
     *
     * @throws Exception
     */
    public function reset(): void
    {
        throw new Exception('Method not supported');
    }
}
