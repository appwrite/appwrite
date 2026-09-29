<?php

declare(strict_types=1);

namespace Utopia\NATS\JetStream;

final class Stream
{
    public function __construct(
        private readonly JetStream $js,
        private StreamInfo $info,
    ) {}

    public function getName(): string
    {
        return $this->info->config->name;
    }

    public function getConfig(): StreamConfig
    {
        return $this->info->config;
    }

    public function getState(): StreamState
    {
        return $this->info->state;
    }

    public function info(bool $refresh = false): StreamInfo
    {
        if ($refresh) {
            $this->info = $this->js->getStreamInfo($this->getName());
        }
        return $this->info;
    }

    public function createConsumer(ConsumerConfig $config): Consumer
    {
        return $this->js->createConsumer($this->getName(), $config);
    }

    public function getConsumer(string $name): Consumer
    {
        return $this->js->getConsumer($this->getName(), $name);
    }

    public function deleteConsumer(string $name): void
    {
        $this->js->deleteConsumer($this->getName(), $name);
    }

    public function purge(?string $subject = null): void
    {
        $this->js->purgeStream($this->getName(), $subject);
    }

    public function delete(): void
    {
        $this->js->deleteStream($this->getName());
    }
}
