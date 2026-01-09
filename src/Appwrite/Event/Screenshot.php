<?php

namespace Appwrite\Event;

use Utopia\Config\Config;
use Utopia\Queue\Publisher;
use Utopia\System\System;

class Screenshot extends Event
{
    protected string $deploymentId = '';

    public function __construct(protected Publisher $publisher)
    {
        parent::__construct($publisher);

        $this
            ->setQueue(System::getEnv('_APP_SCREENSHOTS_QUEUE_NAME', Event::SCREENSHOTS_QUEUE_NAME))
            ->setClass(System::getEnv('_APP_SCREENSHOTS_CLASS_NAME', Event::SCREENSHOTS_CLASS_NAME));
    }

    public function setDeploymentId(string $deploymentId): self
    {
        $this->deploymentId = $deploymentId;

        return $this;
    }

    protected function preparePayload(): array
    {
        $platform = $this->platform;
        if (empty($platform)) {
            $platform = Config::getParam('platform', []);
        }

        return [
            'project' => $this->project,
            'deploymentId' => $this->deploymentId,
            'platform' => $platform,
        ];
    }

    public function reset(): self
    {
        $this->deploymentId = '';
        parent::reset();

        return $this;
    }
}
