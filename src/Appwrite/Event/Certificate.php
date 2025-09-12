<?php

namespace Appwrite\Event;

use Utopia\Database\Document;
use Utopia\Queue\Publisher;

class Certificate extends Event
{
    public const ACTION_VERIFICATION = 'verification';
    public const ACTION_GENERATION = 'generation';

    protected bool $skipRenewCheck = false;
    protected ?Document $domain = null;
    protected ?string $verificationDomainFunction = null; // For example: fra.cloud.appwrite.io
    protected ?string $verificationDomainAPI = null; // For example: fra.appwrite.run
    protected string $action = self::ACTION_GENERATION;

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
     * Set verification domain function.
     *
     * @param ?string $verificationDomainFunction
     * @return self
     */
    public function setVerificationDomainFunction(?string $verificationDomainFunction): self
    {
        $this->verificationDomainFunction = $verificationDomainFunction;

        return $this;
    }

    /**
     * Get verification domain function.
     *
     * @return ?string
     */
    public function getVerificationDomainFunction(): ?string
    {
        return $this->verificationDomainFunction;
    }

    /**
     * Set verification domain api.
     *
     * @param ?string $verificationDomainAPI
     * @return self
     */
    public function setVerificationDomainAPI(?string $verificationDomainAPI): self
    {
        $this->verificationDomainAPI = $verificationDomainAPI;

        return $this;
    }

    /**
     * Get verification domain api.
     *
     * @return ?string
     */
    public function getVerificationDomainAPI(): ?string
    {
        return $this->verificationDomainAPI;
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
     * Set action for this certificate event.
     *
     * @param string $action
     * @return self
     */
    public function setAction(string $action): self
    {
        $this->action = $action;

        return $this;
    }

    /**
     * Get action for this certificate event.
     *
     * @return string
     */
    public function getAction(): string
    {
        return $this->action;
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
            'verificationDomainFunction' => $this->verificationDomainFunction,
            'verificationDomainAPI' => $this->verificationDomainAPI,
            'action' => $this->action
        ];
    }
}
