<?php

namespace Appwrite\Event;

use Resque;
use Utopia\Database\Document;

class Build extends Event
{
    protected string $type = '';
    protected ?Document $resource = null;
    protected ?Document $deployment = null;
    protected ?Document $vcsTemplate = null;
    protected string $vcsCommitHash = '';
    protected string $vcsTargetUrl = '';
    protected ?Document $vcsContribution = null;

    public function __construct()
    {
        parent::__construct(Event::BUILDS_QUEUE_NAME, Event::BUILDS_CLASS_NAME);
    }

    /**
     * Sets template for the build event.
     *
     * @param Document $vcsTemplate
     * @return self
     */
    public function setVcsTemplate(Document $vcsTemplate): self
    {
        $this->vcsTemplate = $vcsTemplate;

        return $this;
    }

     /**
     * Sets commit SHA for the build event.
     *
     * @param string $vcsCommitHash is the commit hash of the incoming commit
     * @return self
     */
    public function setVcsCommitHash(string $vcsCommitHash): self
    {
        $this->vcsCommitHash = $vcsCommitHash;

        return $this;
    }

    /**
     * Sets redirect target url for the deployment
     *
     * @param string $vcsTargetUrl is the url that is to be set
     * @return self
     */
    public function setVcsTargetUrl(string $vcsTargetUrl): self
    {
        $this->vcsTargetUrl = $vcsTargetUrl;

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
     * Sets custom owner and repository for VCS clone command during build
     *
     * @param Document $owner
     * @return self
     */
    public function setVcsContribution(Document $vcsContribution): self
    {
        $this->vcsContribution = $vcsContribution;

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
     * Executes the function event and sends it to the functions worker.
     *
     * @return string|bool
     * @throws \InvalidArgumentException
     */
    public function trigger(): string|bool
    {
        return Resque::enqueue($this->queue, $this->class, [
            'project' => $this->project,
            'resource' => $this->resource,
            'deployment' => $this->deployment,
            'type' => $this->type,
            'vcsTemplate' => $this->vcsTemplate,
            'vcsCommitHash' => $this->vcsCommitHash,
            'vcsTargetUrl' => $this->vcsTargetUrl,
            'vcsContribution' => $this->vcsContribution
        ]);
    }
}
