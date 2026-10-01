<?php

namespace Utopia\Abuse\Adapters;

use Exception;
use Psr\Http\Client\ClientExceptionInterface;
use Utopia\Abuse\Adapter;
use Utopia\Client\Adapter\Curl\Client as CurlAdapter;
use Utopia\Client\Client;
use Utopia\Client\Destination\Anywhere;
use Utopia\Psr7\Request\Factory as RequestFactory;

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

        $request = new RequestFactory()->body('POST', $url, \http_build_query($fields), 'application/x-www-form-urlencoded');

        try {
            $body = (string) new Client(new CurlAdapter(), new Anywhere())->sendRequest($request)->getBody();
        } catch (ClientExceptionInterface) {
            $body = '';
        }

        /** @var array<string, mixed> $result */
        $result = \json_decode($body, true);

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
