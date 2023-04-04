<?php

namespace Appwrite\Event;

use Resque;
use Utopia\Database\Document;

class Transcoding extends Event
{
    protected ?Document $video = null;
    protected ?Document $profile = null;
    protected int $second = 5;
    protected string $output = '';
    protected string $action = '';

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
     * Sets action.
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
     * Returns action.
     *
     * @return string
     */
    public function getAction(): string
    {
        return $this->action;
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
     * Sets second from duration.
     *
     * @param int $second
     * @return self
     */
    public function setSecond(int $second): self
    {
        $this->second = $second;

        return $this;
    }

    /**
     * Returns second from duration.
     *
     * @return int
     */
    public function getSecond(): int
    {
        return $this->second;
    }


    /**
     * Executes the function event and sends it to the functions worker.
     *
     * @return string|bool
     * @throws \InvalidArgumentException
     */
    public function trigger(): string|bool
    {
        $keys = [
            'action' => $this->action,
            'project' => $this->project,
            'user' => $this->user,
            'video' => $this->video,
            'second' => $this->getSecond(),
        ];

        if (!empty($this->getOutput())) {
            $keys = array_merge($keys, [
                    'output' => $this->getOutput(),
                ]);
        }

        if (!empty($this->getProfile())) {
            $keys = array_merge($keys, [
                'profile' => $this->getProfile(),
            ]);
        }

        return Resque::enqueue($this->queue, $this->class, $keys);
    }
}
