<?php

namespace Appwrite\Event;

use Utopia\Database\Document;
use Utopia\Queue\Publisher;
use Utopia\System\System;

class Certificate extends Event
{
    public const string ACTION_DOMAIN_VERIFICATION = 'verification';
    public const string ACTION_GENERATION = 'generation';
    protected bool $skipRenewCheck = false;
    protected string $action = self::ACTION_GENERATION;
    protected ?Document $domain = null;
    protected ?string $validationDomain = null;

    public function __construct(protected Publisher $publisher)
    {
        parent::__construct($publisher);

        $this
            ->setQueue(System::getEnv('_APP_CERTIFICATES_QUEUE_NAME', Event::CERTIFICATES_QUEUE_NAME))
            ->setClass(System::getEnv('_APP_CERTIFICATES_CLASS_NAME', Event::CERTIFICATES_CLASS_NAME));
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
            'validationDomain' => $this->validationDomain,
            'action' => $this->action
        ];
    }
}
