<?php

namespace Appwrite\Event;

use Resque;

class Event
{

    const DATABASE_QUEUE_NAME= 'v1-database';
    const DATABASE_CLASS_NAME = 'DatabaseV1';

    const DELETE_QUEUE_NAME = 'v1-deletes';
    const DELETE_CLASS_NAME = 'DeletesV1';

    const AUDITS_QUEUE_NAME = 'v1-audits';
    const AUDITS_CLASS_NAME = 'AuditsV1';

    const USAGE_QUEUE_NAME = 'v1-usage';
    const USAGE_CLASS_NAME = 'UsageV1';

    const MAILS_QUEUE_NAME = 'v1-mails';
    const MAILS_CLASS_NAME = 'MailsV1';

    const FUNCTIONS_QUEUE_NAME = 'v1-functions';
    const FUNCTIONS_CLASS_NAME = 'FunctionsV1';

    const WEBHOOK_QUEUE_NAME = 'v1-webhooks';
    const WEBHOOK_CLASS_NAME = 'WebhooksV1';

    const TASK_CLASS_NAME = 'TasksV1';

    const CERTIFICATES_QUEUE_NAME = 'v1-certificates';
    const CERTIFICATES_CLASS_NAME = 'CertificatesV1';
    
    
    /**
     * @var string
     */
    protected $queue = '';

    /**
     * @var string
     */
    protected $class = '';

    /**
     * @var array
     */
    protected $params = [];

    /**
     * Event constructor.
     *
     * @param string $queue
     * @param string $class
     */
    public function __construct(string $queue, string $class)
    {
        $this->queue = $queue;
        $this->class = $class;
    }

    /**
     * @param string $queue
     * return $this
     */
    public function setQueue(string $queue): self
    {
        $this->queue = $queue;
        return $this;
    }

    /**
     * @return string
     */
    public function getQueue()
    {
        return $this->queue;
    }

    /**
     * @param string $class
     * return $this
     */
    public function setClass(string $class): self
    {
        $this->class = $class;
        return $this;
    }

    /**
     * @return string
     */
    public function getClass()
    {
        return $this->class;
    }

    /**
     * @param string $key
     * @param mixed  $value
     *
     * @return $this
     */
    public function setParam(string $key, $value): self
    {
        $this->params[$key] = $value;

        return $this;
    }

    /**
     * @param string $key
     *
     * @return mixed|null
     */
    public function getParam(string $key)
    {
        return (isset($this->params[$key])) ? $this->params[$key] : null;
    }

    /**
     * Execute Event.
     */
    public function trigger(): void
    {
        Resque::enqueue($this->queue, $this->class, $this->params);

        $this->reset();
    }

    public function reset(): self
    {
        $this->params = [];

        return $this;
    }
}
