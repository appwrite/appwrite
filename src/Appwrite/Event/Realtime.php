<?php

namespace Appwrite\Event;

use Appwrite\Database\Document;
use Utopia\App;

class Realtime
{
    /**
     * @var string
     */
    protected $project = '';

    /**
     * @var string
     */
    protected $event = '';

    /**
     * @var array
     */
    protected $channels = [];

    /**
     * @var array
     */
    protected $permissions = [];

    /**
     * @var Document
     */
    protected $payload;


    /**
     * Event constructor.
     *
     * @param string $project
     * @param string $event
     * @param array $payload
     */
    public function __construct(string $project, string $event, array $payload)
    {
        $this->project = $project;
        $this->event = $event;
        $this->payload = new Document($payload);
    }

    /**
     * @param string $project
     * return $this
     */
    public function setProject(string $project): self
    {
        $this->project = $project;
        return $this;
    }

    /**
     * @return string
     */
    public function getProject(): string
    {
        return $this->project;
    }

    /**
     * @param string $event
     * return $this
     */
    public function setEvent(string $event): self
    {
        $this->event = $event;
        return $this;
    }

    /**
     * @return string
     */
    public function getEvent(): string
    {
        return $this->event;
    }

    /**
     * @param array $payload
     * return $this
     */
    public function setPayload(array $payload): self
    {
        $this->payload = new Document($payload);
        return $this;
    }

    /**
     * @return Document
     */
    public function getPayload(): Document
    {
        return $this->payload;
    }

    /**
     * Populate channels array based on the event name and payload.
     *
     * @return void
     */
    private function prepareChannels(): void
    {
        switch (true) {
            case strpos($this->event, 'account.') === 0:
                $this->channels[] = 'account.' . $this->payload->getId();
                $this->permissions = ['user:' . $this->payload->getId()];

                break;
            case strpos($this->event, 'database.collections.') === 0:
                $this->channels[] = 'collections';
                $this->channels[] = 'collections.' . $this->payload->getId();
                $this->permissions = $this->payload->getAttribute('$permissions.read');

                break;
            case strpos($this->event, 'database.documents.') === 0:
                $this->channels[] = 'documents';
                $this->channels[] = 'collections.' . $this->payload->getAttribute('$collection') . '.documents';
                $this->channels[] = 'documents.' . $this->payload->getId();
                $this->permissions = $this->payload->getAttribute('$permissions.read');

                break;
            case strpos($this->event, 'storage.') === 0:
                $this->channels[] = 'files';
                $this->channels[] = 'files.' . $this->payload->getId();
                $this->permissions = $this->payload->getAttribute('$permissions.read');

                break;
        }
    }

    /**
     * Execute Event.
     * 
     * @return void
     */
    public function trigger(): void
    {
        $this->prepareChannels();
        if (empty($this->channels)) return;

        $redis = new \Redis();
        $redis->connect(App::getEnv('_APP_REDIS_HOST', ''), App::getEnv('_APP_REDIS_PORT', ''));
        $redis->publish('realtime', json_encode([
            'project' => $this->project,
            'permissions' => $this->permissions,
            'data' => [
                'event' => $this->event,
                'channels' => $this->channels,
                'timestamp' => time(),
                'payload' => $this->payload->getArrayCopy()
            ]
        ]));

        $this->reset();
    }

    public function reset(): self
    {
        $this->event = '';
        $this->payload = $this->channels = [];

        return $this;
    }
}
