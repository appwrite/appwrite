<?php

namespace Appwrite\Event;

use Utopia\Database\Document;
use Utopia\Queue\Publisher;

class Certificate extends Event
{
    protected bool $skipRenewCheck = false;
    protected ?Document $domain = null;
    protected ?string $validationDomain = null;

    public function __construct(protected Publisher $publisher)
    {
        parent::__construct($publisher);

        $this
            ->setQueue(Event::CERTIFICATES_QUEUE_NAME)
            ->setClass(Event::CERTIFICATES_CLASS_NAME);
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
     * Set override for main domain used for validation
     *
     * @param string|null $validationDomain
     * @return self
     */
    public function setValidationDomain(?string $validationDomain): self
    {
        $this->validationDomain = $validationDomain;

        return $this;
    }

    /**
     * Get validation domain
     *
     * @return string|null
     */
    public function getValidationDomain(): ?string
    {
        return $this->validationDomain;
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
     * Prepare the payload for the event
     *
     * @return array
     */
    protected function preparePayload(): array
    {
        return [
            'project' => $this->project,
            'domain' => $this->domain,
            'skipRenewCheck' => $this->skipRenewCheck,
            'validationDomain' => $this->validationDomain
        ];
    }
}
