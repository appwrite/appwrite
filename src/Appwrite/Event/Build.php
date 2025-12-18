<?php

namespace Appwrite\Event;

use Utopia\Config\Config;
use Utopia\Database\Document;
use Utopia\Queue\Publisher;

class Build extends Event
{
    protected string $type = '';
    protected ?Document $resource = null;
    protected ?Document $deployment = null;
    protected ?Document $template = null;

    public function __construct(protected Publisher $publisher)
    {
        parent::__construct($publisher);

        $this
            ->setQueue(Event::BUILDS_QUEUE_NAME)
            ->setClass(Event::BUILDS_CLASS_NAME);
    }

    /**
     * Sets template for the build event.
     *
     * @param Document $template
     * @return self
     */
    public function setTemplate(Document $template): self
    {
        $this->template = $template;

        return $this;
    }

    /**
     * Sets resource document for the build event.
     *
     * @param Document $resource
     * @return self
     */
    public function setResource(Document $resource): self
    {
        $this->resource = $resource;

        return $this;
    }

    /**
     * Returns set resource document for the build event.
     *
     * @return null|Document
     */
    public function getResource(): ?Document
    {
        return $this->resource;
    }

    /**
     * Sets deployment for the build event.
     *
     * @param Document $deployment
     * @return self
     */
    public function setDeployment(Document $deployment): self
    {
        $this->deployment = $deployment;

        return $this;
    }

    /**
     * Returns set deployment for the build event.
     *
     * @return null|Document
     */
    public function getDeployment(): ?Document
    {
        return $this->deployment;
    }

    /**
     * Sets type for the build event.
     *
     * @param string $type Can be `BUILD_TYPE_DEPLOYMENT` or `BUILD_TYPE_RETRY`.
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
     * Prepare payload for queue.
     *
     * @return array
     */
    protected function preparePayload(): array
    {
        $platform = $this->platform;
        if (empty($platform)) {
            $platform = Config::getParam('platform', []);
        }

        return [
            'project' => $this->project,
            'resource' => $this->resource,
            'deployment' => $this->deployment,
            'type' => $this->type,
            'template' => $this->template,
            'platform' => $platform,
        ];
    }

    /**
     * Resets event.
     *
     * @return self
     */
    public function reset(): self
    {
        $this->type = '';
        $this->resource = null;
        $this->deployment = null;
        $this->template = null;
        $this->platform = [];
        parent::reset();

        return $this;
    }
}
