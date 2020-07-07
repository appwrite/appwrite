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
    protected $swoole = null;
    
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
     * @return self
     */
    public function send(string $body = '', int $exit = null): void
    {
        if(!$this->disablePayload) {
            $this->addHeader('X-Debug-Speed', microtime(true) - $this->startTime);

            $this
                ->appendCookies()
                ->appendHeaders()
            ;

            $this->size = $this->size + mb_strlen(implode("\n", $this->headers)) + mb_strlen($body, '8bit');

            $this->swoole->end($body);

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
        $this->swoole->status($this->statusCode);

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