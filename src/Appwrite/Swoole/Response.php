<?php

namespace Appwrite\Swoole;

use Appwrite\Utopia\Response as UtopiaResponse;
use Swoole\Http\Response as SwooleResponse;

class Response extends UtopiaResponse
{
    /**
     * Swoole Response Object
     * 
     * @var SwooleResponse
     */
    protected $swoole;

    /**
     * Mime Types
     *  with compression support
     * 
     * @var array
     */
    protected $compressed = [
        'text/plain' => true,
        'text/css' => true,
        'text/javascript' => true,
        'application/javascript' => true,
        'text/html' => true,
        'text/html; charset=UTF-8' => true,
        'application/json' => true,
        'application/json; charset=UTF-8' => true,
        'image/svg+xml' => true,
        'application/xml+rss' => true,
    ];
    
    /**
     * Response constructor.
     */
    public function __construct(SwooleResponse $response)
    {        
        $this->swoole = $response;
        parent::__construct(\microtime(true));
    }

    /**
     * Output response
     *
     * Generate HTTP response output including the response header (+cookies) and body and prints them.
     *
     * @param string $body
     * @param int $exit exit code or don't exit if code is null
     *
     * @return void
     */
    public function send(string $body = '', int $exit = null): void
    {
        if(!$this->disablePayload) {
            $this->addHeader('X-Debug-Speed', (string)(microtime(true) - $this->startTime));

            $this
                ->appendCookies()
                ->appendHeaders()
            ;

            $chunk = 2000000; // Max chunk of 2 mb
            $length = strlen($body);

            $this->size = $this->size + strlen(implode("\n", $this->headers)) + $length;

            if(array_key_exists(
                $this->contentType,
                $this->compressed
                ) && ($length <= $chunk)) { // Dont compress with GZIP / Brotli if header is not listed and size is bigger than 2mb
                $this->swoole->end($body);
            }
            else {
                for ($i=0; $i < ceil($length / $chunk); $i++) {
                    $this->swoole->write(substr($body, ($i * $chunk), min((($i * $chunk) + $chunk), $length)));
                }

                $this->swoole->end();
            }

            $this->disablePayload();
        }
    }

    /**
     * Append headers
     *
     * Iterating over response headers to generate them using native PHP header function.
     * This method is also responsible for generating the response and content type headers.
     *
     * @return self
     */
    protected function appendHeaders(): self
    {
        // Send status code header
        $this->swoole->status((string)$this->statusCode);

        // Send content type header
        $this
            ->addHeader('Content-Type', $this->contentType)
        ;

        // Set application headers
        foreach ($this->headers as $key => $value) {
            $this->swoole->header($key, $value);
        }

        return $this;
    }

    /**
     * Append cookies
     *
     * Iterating over response cookies to generate them using native PHP cookie function.
     *
     * @return self
     */
    protected function appendCookies(): self
    {
        foreach ($this->cookies as $cookie) {
            $this->swoole->cookie(
                $cookie['name'],
                $cookie['value'],
                $cookie['expire'],
                $cookie['path'],
                $cookie['domain'],
                $cookie['secure'],
                $cookie['httponly'],
                $cookie['samesite'],
            );
        }

        return $this;
    }
}