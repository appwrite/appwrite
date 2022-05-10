<?php

namespace Appwrite\Event;

use Resque;
use Utopia\Database\Document;

class Certificate extends Event
{
    protected bool $validateTarget = false;
    protected bool $validateCNAME = false;
    protected ?Document $domain = null;

    public function __construct()
    {
        parent::__construct(Event::CERTIFICATES_QUEUE_NAME, Event::CERTIFICATES_CLASS_NAME);
    }

    /**
     * Set domain for this certificates event.
     *
     * @param Document $domain
     * @return self
     */
    public function setDomain(Document $domain): self
    {
        $this->domain = $domain;

        return $this;
    }

    /**
     * Returns the set domain for this certificate event.
     *
     * @return null|Document
     */
    public function getDomain(): ?Document
    {
        return $this->domain;
    }

    /**
     * Set if the target needs be validated.
     *
     * @param bool $validateTarget
     * @return self
     */
    public function setValidateTarget(bool $validateTarget): self
    {
        $this->validateTarget = $validateTarget;

        return $this;
    }

    /**
     * Return if the domain target will be validated.
     *
     * @return bool
     */
    public function getValidateTarget(): bool
    {
        return $this->validateTarget;
    }

    /**
     * Set if the CNAME needs to be validated.
     *
     * @param bool $validateCNAME
     * @return self
     */
    public function setValidateCNAME(bool $validateCNAME): self
    {
        $this->validateCNAME = $validateCNAME;

        return $this;
    }

    /**
     * Return if the CNAME will be validated.
     *
     * @return bool
     */
    public function getValidateCNAME(): bool
    {
        return $this->validateCNAME;
    }

    /**
     * Executes the event and sends it to the certificates worker.
     *
     * @return string|bool
     * @throws \InvalidArgumentException
     */
    public function trigger(): string|bool
    {
        return Resque::enqueue($this->queue, $this->class, [
            'project' => $this->project,
            'domain' => $this->domain,
            'validateTarget' => $this->validateTarget,
            'validateCNAME' => $this->validateCNAME
        ]);
    }
}
