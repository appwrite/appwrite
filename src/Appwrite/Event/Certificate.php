<?php

namespace Appwrite\Event;

use Resque;
use Utopia\Database\Document;

class Certificate extends Event
{
    protected ?Document $domain = null;
    protected bool $validateTarget = false;
    protected bool $validateCNAME = false;

    public function __construct()
    {
        parent::__construct(Event::CERTIFICATES_QUEUE_NAME, Event::CERTIFICATES_CLASS_NAME);
    }

    public function setDomain(Document $domain): self
    {
        $this->domain = $domain;

        return $this;
    }

    public function getDomain(): ?Document
    {
        return $this->domain;
    }

    public function setValidateTarget(bool $validateTarget): self
    {
        $this->validateTarget = $validateTarget;

        return $this;
    }

    public function getValidateTarget(): bool
    {
        return $this->validateTarget;
    }

    public function setValidateCNAME(bool $validateCNAME): self
    {
        $this->validateCNAME = $validateCNAME;

        return $this;
    }

    public function getValidateCNAME(): bool
    {
        return $this->validateCNAME;
    }

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