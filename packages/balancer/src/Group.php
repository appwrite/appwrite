<?php

namespace Utopia\Balancer;

use Utopia\Telemetry\Adapter as Telemetry;
use Utopia\Telemetry\Adapter\None as NoTelemetry;
use Utopia\Telemetry\Histogram;

class Group
{
    /**
     * @var Balancer[]
     */
    protected array $balancers = [];

    private Histogram $runHistogram;

    public function __construct(private ?string $name = null)
    {
        $this->setTelemetry(new NoTelemetry());
    }

    public function setTelemetry(Telemetry $telemetry): void
    {
        $this->runHistogram = $telemetry->createHistogram(
            'balancer.run.duration',
            's',
            null,
            ['ExplicitBucketBoundaries' => [0.005, 0.01, 0.025, 0.05, 0.075, 0.1, 0.25, 0.5, 0.75, 1, 2.5, 5, 7.5, 10]]
        );
    }

    /**
     * @param Balancer $balancer
     * @return self
     */
    public function add(Balancer $balancer): self
    {
        $this->balancers[] = $balancer;
        return $this;
    }

    /**
     * @return Option[]
     */
    public function getOptions(): array
    {
        $options = [];

        foreach ($this->balancers as $balancer) {
            foreach ($balancer->getOptions() as $option) {
                $options[] = $option;
            }
        }

        return $options;
    }

    public function run(): ?Option
    {
        $option = null;

        $start = microtime(true);
        foreach ($this->balancers as $balancer) {
            $option = $balancer->run();

            if ($option !== null) {
                break;
            }
        }
        $end = microtime(true);
        $this->runHistogram->record($end - $start, ['name' => $this->name]);

        return $option;
    }
}
