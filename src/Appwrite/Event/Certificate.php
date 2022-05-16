<?php

namespace Appwrite\Event;

use Resque;
use Utopia\Database\Document;

class Certificate extends Event
{
    protected bool $skipRenewCheck = false;
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
     * Set if the certificate needs to be validated.
     *
     * @param bool $skipRenewCheck
     * @return self
     */
    public function setSkipRenewCheck(bool $skipRenewCheck): self
    {
        $this->skipRenewCheck = $skipRenewCheck;

        return $this;
    }

    /**
     * Return if the certificate needs be validated.
     *
     * @return bool
     */
    public function getSkipRenewCheck(): bool
    {
        return $this->skipRenewCheck;
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
            'skipRenewCheck' => $this->skipRenewCheck
        ]);
    }
}
