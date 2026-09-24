<?php

namespace Utopia\Detector\Detector;

use Utopia\Detector\Detection\Runtime as RuntimeDetection;
use Utopia\Detector\Detector;

class Runtime extends Detector
{
    /**
     * @var array<RuntimeDetection>
     */
    protected array $options = [];

    protected Strategy $strategy;

    protected string $packager = 'pnpm';

    public function __construct(Strategy $strategy, string $packager = 'pnpm')
    {
        parent::__construct();
        $this->strategy = $strategy;
        $this->packager = $packager;
    }

    public function detect(): ?RuntimeDetection
    {
        $inputs = array_map(fn ($input) => $input['content'], $this->inputs);

        switch ($this->strategy->getValue()) {
            case Strategy::FILEMATCH:
                foreach ($this->options as $detector) {
                    $detectorFiles = $detector->getFiles();
                    $matches = array_intersect($detectorFiles, $inputs);
                    if (count($matches) > 0) {
                        $detector->setPackager($this->packager);

                        return $detector;
                    }
                }
                break;

            case Strategy::EXTENSION:
                foreach ($this->options as $detector) {
                    $detectorExtensions = $detector->getFileExtensions();
                    $inputExtensions = array_map(function ($file) {
                        return pathinfo($file, PATHINFO_EXTENSION);
                    }, $inputs);
                    $matches = array_intersect($detectorExtensions, $inputExtensions);
                    if (count($matches) > 0) {
                        $detector->setPackager($this->packager);

                        return $detector;
                    }
                }
                break;

            case Strategy::LANGUAGES:
                foreach ($this->options as $detector) {
                    $detectorLanguages = $detector->getLanguages();
                    $matches = array_intersect($detectorLanguages, $inputs);
                    if (count($matches) > 0) {
                        $detector->setPackager($this->packager);

                        return $detector;
                    }
                }
                break;
        }

        return null;
    }
}
