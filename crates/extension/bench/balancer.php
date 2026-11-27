<?php

// Times utopia-php/balancer: `python3 crates/extension/bench/run.py crates/extension/bench/balancer.php . <utopia.so> 5`.

require __DIR__ . '/../../../vendor/autoload.php';

use Utopia\Balancer\Algorithm\First;
use Utopia\Balancer\Algorithm\Last;
use Utopia\Balancer\Algorithm\Random;
use Utopia\Balancer\Algorithm\RoundRobin;
use Utopia\Balancer\Balancer;
use Utopia\Balancer\Group;
use Utopia\Balancer\Option;

// A run meant for the extension that gets the PHP classes would time PHP against PHP.
$internal = (new ReflectionClass(Balancer::class))->isInternal();
$requested = in_array('balancer', array_map(trim(...), explode(',', getenv('_APP_EXTENSION_LIBRARIES') ?: '')), true);
if ($internal !== $requested) {
    fwrite(STDERR, '_APP_EXTENSION_LIBRARIES ' . ($requested ? 'names' : 'does not name') . ' balancer, but the other implementation loaded' . PHP_EOL);
    exit(1);
}

/** As Cloud's CdnOption: an Option subclass with typed getters over its state. */
class CdnOption extends Option
{
    public function __construct(string $provider, bool $edge = false)
    {
        parent::__construct(['provider' => $provider, 'edge' => $edge]);
    }

    public function isEdge(): bool
    {
        return $this->getState('edge', false) === true;
    }
}

/** Nanoseconds per call of $run, the best of 7 batches of $n. */
function measure(int $n, callable $run): float
{
    $run();
    $best = INF;
    for ($batch = 0; $batch < 7; $batch++) {
        $start = hrtime(true);
        for ($i = 0; $i < $n; $i++) {
            $run();
        }
        $best = min($best, (hrtime(true) - $start) / $n);
    }

    return $best;
}

$workers = [
    ['hostname' => 'worker-1', 'online' => true, 'cpu' => 80],
    ['hostname' => 'worker-2', 'online' => false, 'cpu' => 20],
    ['hostname' => 'worker-3', 'online' => true, 'cpu' => 35],
    ['hostname' => 'worker-4', 'online' => true, 'cpu' => 5],
];
$options = array_map(fn (array $state) => new Option($state), $workers);
$balancer = function (object $algorithm, bool $filtered) use ($options): Balancer {
    $balancer = new Balancer($algorithm);
    foreach ($options as $option) {
        $balancer->addOption($option);
    }
    if ($filtered) {
        $balancer->addFilter(fn (Option $option) => $option->getState('online') === true);
        $balancer->addFilter(fn (Option $option) => $option->getState('cpu') < 50);
    }

    return $balancer;
};

$option = new Option($workers[0]);
$first = $balancer(new First(), false);
$firstFiltered = $balancer(new First(), true);
$last = $balancer(new Last(), false);
$random = $balancer(new Random(), false);
$randomFiltered = $balancer(new Random(), true);
$roundRobin = $balancer(new RoundRobin(-1), false);
$roundRobinFiltered = $balancer(new RoundRobin(-1), true);
$list = $options;
$firstAlgorithm = new First();
$roundRobinAlgorithm = new RoundRobin(-1);

$cdn = new Balancer(new First());
$cdn->addOption(new CdnOption('fastly', true))->addOption(new CdnOption('fastly'))->addOption(new CdnOption('cloudflare'));
$cdn->addFilter(fn (CdnOption $option): bool => $option->isEdge() === false);
$cdnOption = new CdnOption('fastly', true);

$group = new Group('workers');
$group->add($balancer(new First(), true))->add($balancer(new Last(), false));

$results = [
    'new Option' => measure(100000, fn () => new Option(['hostname' => 'worker-1', 'online' => true, 'cpu' => 80])),
    'getState' => measure(200000, fn () => $option->getState('hostname')),
    'getState default' => measure(200000, fn () => $option->getState('missing', false)),
    'setState' => measure(200000, fn () => $option->setState('cpu', 42)),
    'setState + deleteState' => measure(100000, fn () => $option->setState('attempted', true)->deleteState('attempted')),
    'getStates' => measure(200000, fn () => $option->getStates()),
    'subclass isEdge' => measure(200000, fn () => $cdnOption->isEdge()),
    'new Balancer + 4 options' => measure(50000, fn () => (new Balancer(new First()))->addOption($options[0])->addOption($options[1])->addOption($options[2])->addOption($options[3])),
    'addFilter' => measure(50000, fn () => (new Balancer(new First()))->addFilter(fn (Option $o) => true)),
    'run First' => measure(200000, fn () => $first->run()),
    'run First, 2 filters' => measure(100000, fn () => $firstFiltered->run()),
    'run Last' => measure(200000, fn () => $last->run()),
    'run Random' => measure(200000, fn () => $random->run()),
    'run Random, 2 filters' => measure(100000, fn () => $randomFiltered->run()),
    'run RoundRobin' => measure(200000, fn () => $roundRobin->run()),
    'run RoundRobin, 2 filters' => measure(100000, fn () => $roundRobinFiltered->run()),
    'getFilteredOptions, 2 filters' => measure(100000, fn () => $firstFiltered->getFilteredOptions()),
    'getOptions' => measure(200000, fn () => $first->getOptions()),
    'First::run(array)' => measure(200000, fn () => $firstAlgorithm->run($list)),
    'RoundRobin::run(array)' => measure(200000, fn () => $roundRobinAlgorithm->run($list)),
    'CDN purge filter' => measure(100000, fn () => $cdn->getFilteredOptions()),
    'avatars provider loop' => measure(20000, function () {
        $balancer = new Balancer(new First());
        foreach (['gravatar', 'github', 'fallback'] as $provider) {
            $balancer->addOption(new Option(['provider' => $provider]));
        }
        $balancer->addFilter(fn (Option $option) => $option->getState('provider') !== 'gravatar');
        $balancer->addFilter(fn (Option $option) => !$option->getState('attempted', false));
        while (($option = $balancer->run()) !== null) {
            $option->setState('attempted', true);
        }
    }),
    'new Group' => measure(50000, fn () => new Group('workers')),
    'Group run' => measure(100000, fn () => $group->run()),
    'Group getOptions' => measure(100000, fn () => $group->getOptions()),
];
$results['peak memory MB'] = round(memory_get_peak_usage() / 1048576, 1);

echo json_encode($results), PHP_EOL;
