<?php

namespace Utopia\Detector\Detector;

use Utopia\Detector\Detection\Packager as PackagerDetection;
use Utopia\Detector\Detector;

class Packager extends Detector
{
    /**
     * @var array<PackagerDetection>
     */
    protected array $options = [];

    public function __construct()
    {
        parent::__construct();
    }

    public function detect(): ?PackagerDetection
    {
        $files = array_map(fn ($input) => $input['content'], $this->inputs);

        foreach ($this->options as $packager) {
            $matches = array_intersect($packager->getFiles(), $files);
            if (count($matches) > 0) {
                return $packager;
            }
        }

        return null;
    }
}
