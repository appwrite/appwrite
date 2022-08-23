<?php

namespace Appwrite\Event;

use Resque;
use Utopia\Database\Document;

class Transcoding extends Event
{
    protected string $videoId = '';
    protected string $profileId = '';

    public function __construct()
    {
        parent::__construct(Event::TRANSCODING_QUEUE_NAME, Event::TRANSCODING_CLASS_NAME);
    }

    /**
     * Sets videoId event.
     *
     * @param $videoId string
     * @return self
     */
    public function setVideoId(string $videoId): self
    {
        $this->videoId = $videoId;

        return $this;
    }

    /**
     * Returns bucketId.
     *
     * @return null|Document
     */
    public function getVideoId(): ?string
    {
        return $this->videoId;
    }

    /**
     * Sets profileId event.
     *
     * @param $profileId string
     * @return self
     */
    public function setProfileId(string $profileId): self
    {
        $this->profileId = $profileId;

        return $this;
    }

    /**
     * Returns profileId.
     *
     * @return null|Document
     */
    public function getProfileId(): ?string
    {
        return $this->profileId;
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
            'user' => $this->user,
            'videoId' => $this->videoId,
            'profileId' => $this->profileId,
        ]);
    }
}
