<?php

namespace Appwrite\Event;

use Resque;
use Utopia\Database\Document;

class Transcoding extends Event
{
    protected string $bucketId = '';
    protected string $fileId = '';


    public function __construct()
    {
        parent::__construct(Event::TRANSCODING_QUEUE_NAME, Event::TRANSCODING_CLASS_NAME);
    }

    /**
     * Sets bucketId event.
     *
     * @param $bucketId string
     * @return self
     */
    public function setBucketId(string $bucketId): self
    {
        $this->bucketId = $bucketId;

        return $this;
    }

    /**
     * Returns bucketId.
     *
     * @return null|Document
     */
    public function getBucketId(): ?string
    {
        return $this->bucketId;
    }

    /**
     * Sets fileId.
     *
     *  @param $fileId string
     * @return self
     */
    public function setFileId(string $fileId): self
    {
        $this->fileId = $fileId;

        return $this;
    }

    /**
     * Returns fileId.
     *
     * @return null|Document
     */
    public function getFileId(): ?string
    {
        return $this->fileId;
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
            'bucketId' => $this->bucketId,
            'fileId' => $this->fileId,
        ]);
    }
}
