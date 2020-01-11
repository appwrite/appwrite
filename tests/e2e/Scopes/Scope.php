<?php

namespace Tests\E2E\Scopes;

use Tests\E2E\Client;
use PHPUnit\Framework\TestCase;

abstract class Scope extends TestCase
{
    /**
     * @var Client
     */
    protected $client = null;

    /**
     * @var string
     */
    protected $endpoint = 'http://localhost/v1';

    protected function setUp(): void
    {
        $this->client = new Client();

        $this->client
            ->setEndpoint($this->endpoint)
        ;
    }

    protected function tearDown(): void
    {
        $this->client = null;
    }

    protected function getLastEmail():array
    {
        sleep(3);
        $emails = json_decode(file_get_contents('http://maildev/email'), true);

        if($emails && is_array($emails)) {
            return end($emails);
        }

        return [];
    }

    /**
     * @return array
     */
    abstract public function getHeaders():array;

    /**
     * @return array
     */
    abstract public function getProject():array;
}
