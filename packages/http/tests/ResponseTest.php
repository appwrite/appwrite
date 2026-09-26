<?php

declare(strict_types=1);

namespace Utopia\Http;

use PHPUnit\Framework\TestCase;
use Utopia\Http\Adapter\FPM\Response;

final class FPMResponseTest extends TestCase
{
    protected ?Response $response;

    public function setUp(): void
    {
        $this->response = new Response();
    }

    public function tearDown(): void
    {
        $this->response = null;
    }

    public function testCanSetContentType(): void
    {
        $contentType = $this->response->setContentType(Response::CONTENT_TYPE_HTML, Response::CHARSET_UTF8);

        // Assertions
        $this->assertInstanceOf('Utopia\Http\Response', $contentType);
    }

    public function testCanSetStatus(): void
    {
        $status = $this->response->setStatusCode(Response::STATUS_CODE_OK);

        // Assertions
        $this->assertInstanceOf('Utopia\Http\Response', $status);

        try {
            $this->response->setStatusCode(0); // Unknown status code
        } catch (\Exception $e) {
            $this->assertInstanceOf('\Exception', $e);

            return;
        }

        $this->fail('Expected exception');
    }

    public function testCanGetStatus(): void
    {
        $status = $this->response->setStatusCode(Response::STATUS_CODE_OK);

        // Assertions
        $this->assertInstanceOf('Utopia\Http\Response', $status);
        $this->assertSame(Response::STATUS_CODE_OK, $this->response->getStatusCode());
    }

    public function testCanAddHeader(): void
    {
        $result = $this->response->addHeader('key', 'value');
        $this->assertSame($this->response, $result);
    }

    public function testCanAddCookie(): void
    {
        $result = $this->response->addCookie('name', 'value');
        $this->assertSame($this->response, $result);

        //test cookie case insensitive
        $result = $this->response->addCookie('cookieName', 'cookieValue');
        $result->getCookies()['cookiename']['name'] = 'cookiename';
        $result->getCookies()['cookiename']['value'] = 'cookieValue';
    }

    public function testCanSend(): void
    {
        ob_start(); //Start of build

        @$this->response
            ->addHeader('key', 'value')
            ->addCookie('name', 'value')
            ->send('body'); //FIXME we have a problem with header printing

        $html = ob_get_contents();
        ob_end_clean(); //End of build

        $this->assertSame('body', $html);
    }

    public function testCanSendMinimalCookie(): void
    {
        ob_start(); //Start of build

        // A cookie with only a name (null value, no SameSite/secure/etc.) must
        // not trigger a TypeError when proxied to setcookie()/Swoole's cookie().
        @$this->response
            ->addCookie('name')
            ->send('body');

        $html = ob_get_contents();
        ob_end_clean(); //End of build

        $this->assertSame('body', $html);
    }

    public function testCanSendRedirect(): void
    {
        ob_start(); //Start of build

        @$this->response->redirect('http://www.example.com');

        $html = ob_get_contents();
        ob_end_clean(); //End of build

        $this->assertSame('', $html);

        ob_start(); //Start of build

        @$this->response->redirect('http://www.example.com', 300);

        $html = ob_get_contents();
        ob_end_clean(); //End of build

        $this->assertSame('', $html);
    }

    public function testCanSendText(): void
    {
        ob_start(); //Start of build

        @$this->response->text('HELLO WORLD');

        $html = ob_get_contents();
        ob_end_clean(); //End of build

        $this->assertSame('HELLO WORLD', $html);
        $this->assertSame('text/plain; charset=UTF-8', $this->response->getContentType());
    }

    public function testCanSendHtml(): void
    {
        ob_start(); //Start of build

        @$this->response->html('<html></html>');

        $html = ob_get_contents();
        ob_end_clean(); //End of build

        $this->assertSame('<html></html>', $html);
        $this->assertSame('text/html; charset=UTF-8', $this->response->getContentType());
    }

    public function testCanSendJson(): void
    {
        ob_start(); //Start of build

        @$this->response->json(['key' => 'value']);

        $html = ob_get_contents();
        ob_end_clean(); //End of build

        $this->assertSame('{"key":"value"}', $html);
        $this->assertSame('application/json; charset=UTF-8', $this->response->getContentType());
    }

    public function testCanSendJsonp(): void
    {
        ob_start(); //Start of build

        @$this->response->jsonp('test', ['key' => 'value']);

        $html = ob_get_contents();
        ob_end_clean(); //End of build

        $this->assertSame('parent.test({"key":"value"});', $html);
        $this->assertSame('text/javascript; charset=UTF-8', $this->response->getContentType());
    }

    public function testCanSendIframe(): void
    {
        ob_start(); //Start of build

        @$this->response->iframe('test', ['key' => 'value']);

        $html = ob_get_contents();
        ob_end_clean(); //End of build

        $this->assertSame('<script type="text/javascript">window.parent.test({"key":"value"});</script>', $html);
        $this->assertSame('text/html; charset=UTF-8', $this->response->getContentType());
    }
}
