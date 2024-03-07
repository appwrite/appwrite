<?php

namespace Appwrite\Event;

use Utopia\Database\Document;
use Utopia\Queue\Client;
use Utopia\Queue\Connection;

class Func extends Event
{
    protected string $jwt = '';
    protected string $type = '';
    protected string $body = '';
    protected string $path = '';
    protected string $method = '';
    protected array $headers = [];
    protected ?Document $function = null;
    protected ?Document $execution = null;

    public function __construct(protected Connection $connection)
    {
        parent::__construct($connection);

        $this
            ->setQueue(Event::FUNCTIONS_QUEUE_NAME)
            ->setClass(Event::FUNCTIONS_CLASS_NAME);
    }

    /**
     * Sets function document for the function event.
     *
     * @param Document $function
     * @return self
     */
    public function setFunction(Document $function): self
    {
        $this->function = $function;

        return $this;
    }

    /**
     * Returns set function document for the function event.
     *
     * @return null|Document
     */
    public function getFunction(): ?Document
    {
        return $this->function;
    }

    /**
     * Sets execution for the function event.
     *
     * @param Document $execution
     * @return self
     */
    public function setExecution(Document $execution): self
    {
        $this->execution = $execution;

        return $this;
    }

    /**
     * Returns set execution for the function event.
     *
     * @return null|Document
     */
    public function getExecution(): ?Document
    {
        return $this->execution;
    }

    /**
     * Sets type for the function event.
     *
     * @param string $type Can be `schedule`, `event` or `http`.
     * @return self
     */
    public function setType(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    /**
     * Returns set type for the function event.
     *
     * @return string
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Sets custom body for the function event.
     *
     * @param string $body
     * @return self
     */
    public function setBody(string $body): self
    {
        $this->body = $body;

        return $this;
    }

    /**
     * Sets custom method for the function event.
     *
     * @param string $method
     * @return self
     */
    public function setMethod(string $method): self
    {
        $this->method = $method;

        return $this;
    }

    /**
     * Sets custom path for the function event.
     *
     * @param string $path
     * @return self
     */
    public function setPath(string $path): self
    {
        $this->path = $path;

        return $this;
    }

    /**
     * Sets custom headers for the function event.
     *
     * @param string $headers
     * @return self
     */
    public function setHeaders(array $headers): self
    {
        $this->headers = $headers;

        return $this;
    }

    /**
     * Returns set custom data for the function event.
     *
     * @return string
     */
    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * Sets JWT for the function event.
     *
     * @param string $jwt
     * @return self
     */
    public function setJWT(string $jwt): self
    {
        $this->jwt = $jwt;

        return $this;
    }

    /**
     * Returns set JWT for the function event.
     *
     * @return string
     */
    public function getJWT(): string
    {
        return $this->jwt;
    }

    /**
     * Executes the function event and sends it to the functions worker.
     *
     * @return string|bool
     * @throws \InvalidArgumentException
     */
    public function trigger(): string|bool
    {
        if ($this->paused) {
            return false;
        }

        $client = new Client($this->queue, $this->connection);

        $events = $this->getEvent() ? Event::generateEvents($this->getEvent(), $this->getParams()) : null;

        return $client->enqueue([
            'project' => $this->project,
            'user' => $this->user,
            'function' => $this->function,
            'execution' => $this->execution,
            'type' => $this->type,
            'jwt' => $this->jwt,
            'payload' => $this->payload,
            'events' => $events,
            'body' => $this->body,
            'path' => $this->path,
            'headers' => $this->headers,
            'method' => $this->method,
        ]);
    }

    /**
     * Generate a function event from a base event
     *
     * @param Event $event
     *
     * @return self
     *
     */
    public function from(Event $event): self
    {
        $this->project = $event->getProject();
        $this->user = $event->getUser();
        $this->payload = $event->getPayload();
        $this->event = $event->getEvent();
        $this->params = $event->getParams();
        return $this;
    }
}
