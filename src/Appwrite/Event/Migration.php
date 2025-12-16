<?php

namespace Appwrite\Event;

use Utopia\Config\Config;
use Utopia\Database\Document;
use Utopia\Queue\Publisher;

class Migration extends Event
{
    protected string $type = '';
    protected ?Document $migration = null;

    public function __construct(protected Publisher $publisher)
    {
        parent::__construct($publisher);

        $this
            ->setQueue(Event::MIGRATIONS_QUEUE_NAME)
            ->setClass(Event::MIGRATIONS_CLASS_NAME);
    }

    /**
     * Sets migration document for the migration event.
     *
     * @param Document $migration
     * @return self
     */
    public function setMigration(Document $migration): self
    {
        $this->migration = $migration;

        return $this;
    }

    /**
     * Returns set migration document for the function event.
     *
     * @return null|Document
     */
    public function getMigration(): ?Document
    {
        return $this->migration;
    }

    /**
     * Sets migration type for the migration event.
     *
     * @param string $type
     *
     * @return self
     */
    public function setType(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    /**
     * Returns set migration type for the migration event.
     *
     * @return string
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Prepare the payload for the migration event.
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
            'user' => $this->user,
            'migration' => $this->migration,
            'platform' => $platform,
        ];
    }
}
