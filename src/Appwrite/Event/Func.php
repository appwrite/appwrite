<?php

namespace Appwrite\Event;

use Utopia\Config\Config;
use Utopia\Database\Document;
use Utopia\Queue\Publisher;

class Func extends Event
{
    public const TYPE_ASYNC_WRITE = 'async_write';

    protected string $jwt = '';
    protected string $type = '';
    protected string $body = '';
    protected string $path = '';
    protected string $method = '';
    protected array $headers = [];
    protected ?string $functionId = null;
    protected ?Document $function = null;
    protected ?Document $execution = null;

    public function __construct(protected Publisher $publisher)
    {
        parent::__construct($publisher);

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
     * Sets function id for the function event.
     *
     * @param string $functionId
     */
    public function setFunctionId(string $functionId): self
    {
        $this->functionId = $functionId;

        return $this;
    }

    /**
     * Returns set function id for the function event.
     *
     * @return string|null
     */
    public function getFunctionId(): ?string
    {
        return $this->functionId;
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
     * @param array $headers
     * @return self
     */
    public function setHeaders(array $headers): self
    {
        $this->headers = $headers;

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
     * Prepare payload for the function event.
     *
     * @return array
     */
    protected function preparePayload(): array
    {
        $events = $this->getEvent() ? Event::generateEvents($this->getEvent(), $this->getParams()) : null;

        $platform = $this->platform;
        if (empty($platform)) {
            $platform = Config::getParam('platform', []);
        }

        return [
            'project' => $this->project,
            'user' => $this->user,
            'userId' => $this->userId,
            'function' => $this->function,
            'functionId' => $this->functionId,
            'execution' => $this->execution,
            'type' => $this->type,
            'jwt' => $this->jwt,
            'payload' => $this->payload,
            'events' => $events,
            'body' => $this->body,
            'path' => $this->path,
            'headers' => $this->headers,
            'method' => $this->method,
            'platform' => $platform,
        ];
    }
}
