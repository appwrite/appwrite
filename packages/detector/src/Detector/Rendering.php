<?php

namespace Utopia\Detector\Detector;

use Utopia\Detector\Detection\Rendering as RenderingDetection;
use Utopia\Detector\Detection\Rendering\XStatic;
use Utopia\Detector\Detector;

class Rendering extends Detector
{
    protected string $framework;

    /**
     * @var array<RenderingDetection>
     */
    protected array $options = [];

    public function __construct(string $framework)
    {
        parent::__construct();

        $this->framework = $framework;
    }

    public function detect(): RenderingDetection
    {
        $files = array_map(fn ($input) => $input['content'], $this->inputs);

        foreach ($this->options as $strategy) {
            $matches = array_intersect($strategy->getFiles($this->framework), $files);
            if (count($matches) > 0) {
                return $strategy;
            }
        }

        // set fallback file for Static if there is only one html file
        $htmlFiles = [];
        foreach ($files as $file) {
            if (\pathinfo($file, PATHINFO_EXTENSION) === 'html') {
                $htmlFiles[] = $file;
            }
        }

        if (\count($htmlFiles) === 1) {
            return new XStatic($htmlFiles[0]);
        } else {
            return new XStatic();
        }
    }
}
