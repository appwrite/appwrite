<?php

namespace Appwrite\Event;

use Resque;
use Utopia\Database\Document;

class Transcoding extends Event
{
    protected Document $video;

    protected Document $profile;

    protected string $output;

    public function __construct()
    {
        parent::__construct(Event::TRANSCODING_QUEUE_NAME, Event::TRANSCODING_CLASS_NAME);
    }

    /**
     * Sets output.
     *
     * @param string $output
     * @return self
     */
    public function setOutput(string $output): self
    {
        $this->output = $output;

        return $this;
    }

    /**
     * Returns output.
     *
     * @return string
     */
    public function getOutput(): string
    {
        return $this->output;
    }


    /**
     * Sets video.
     *
     * @param Document $video
     * @return self
     */
    public function setVideo(Document $video): self
    {
        $this->video = $video;

        return $this;
    }

    /**
     * Returns video.
     *
     * @return null|Document
     */
    public function getVideo(): ?string
    {
        return $this->video;
    }

    /**
     * Sets profile.
     *
     * @param Document $profile
     * @return self
     */
    public function setProfile(Document $profile): self
    {
        $this->profile = $profile;

        return $this;
    }

    /**
     * Returns profile.
     *
     * @return null|Document
     */
    public function getProfile(): ?Document
    {
        return $this->profile;
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
            'output' => $this->output,
            'project' => $this->project,
            'user' => $this->user,
            'video' => $this->video,
            'profile' => $this->profile,
        ]);
    }
}
