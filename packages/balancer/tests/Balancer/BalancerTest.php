<?php

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Balancer\Algorithm\First;
use Utopia\Balancer\Algorithm\Last;
use Utopia\Balancer\Algorithm\Random;
use Utopia\Balancer\Algorithm\RoundRobin;
use Utopia\Balancer\Balancer;
use Utopia\Balancer\Group;
use Utopia\Balancer\Option;

class BalancerTest extends TestCase
{
    public function testBalancer(): void
    {
        $balancer = new Balancer(new RoundRobin(-1));

        $this->assertInstanceOf(RoundRobin::class, $balancer->getAlgo());

        $balancer
            ->addOption(new Option(['hostname' => 'worker-1', 'isOnline' => true, 'cpu' => 80]))
            ->addOption(new Option(['hostname' => 'worker-2', 'isOnline' => false, 'cpu' => 20]))
            ->addOption(new Option(['hostname' => 'worker-3', 'isOnline' => true, 'cpu' => 35]));

        $option = $balancer->run() ?? new Option([]);
        $this->assertEquals('worker-1', $option->getState('hostname'));

        $balancer->addFilter(fn ($option) => $option->getState('isOnline') === true);

        $option = $balancer->run() ?? new Option([]);
        $this->assertEquals('worker-3', $option->getState('hostname'));

        $option = $balancer->run() ?? new Option([]);
        $this->assertEquals('worker-1', $option->getState('hostname'));

        $balancer->addFilter(fn ($option) => $option->getState('cpu') < 50);

        $option = $balancer->run() ?? new Option([]);
        $this->assertEquals('worker-3', $option->getState('hostname'));

        $option = $balancer->run() ?? new Option([]);
        $this->assertEquals('worker-3', $option->getState('hostname'));

        $option = $balancer->run() ?? new Option([]);
        $this->assertEquals('worker-3', $option->getState('hostname'));

        $balancer->addOption(new Option(['hostname' => 'worker-4', 'isOnline' => true, 'cpu' => 5]));

        $option = $balancer->run() ?? new Option([]);
        $this->assertEquals('worker-4', $option->getState('hostname'));

        $option = $balancer->run() ?? new Option([]);
        $this->assertEquals('worker-3', $option->getState('hostname'));

        $option = $balancer->run() ?? new Option([]);
        $this->assertEquals('worker-4', $option->getState('hostname'));

        $balancer = new Balancer(new Random());
        $this->assertInstanceOf(Random::class, $balancer->getAlgo());
    }

    public function testAlgorithms(): void
    {
        $options = [
            new Option([ 'dataCenter' => 'fra-1' ]),
            new Option([ 'dataCenter' => 'fra-2' ]),
            new Option([ 'dataCenter' => 'lon-1' ]),
        ];

        $algo = new First();
        $option = $algo->run($options) ?? new Option([]);
        $this->assertEquals("fra-1", $option->getState('dataCenter'));

        $algo = new Last();
        $option = $algo->run($options) ?? new Option([]);
        $this->assertEquals("lon-1", $option->getState('dataCenter'));

        $algo = new Random();
        $option = $algo->run($options) ?? new Option([]);
        $this->assertTrue(\in_array($option->getState('dataCenter'), ['fra-1', 'fra-2', 'lon-1']));
        $this->assertTrue(\in_array($option->getState('dataCenter'), ['fra-1', 'fra-2', 'lon-1']));
        $this->assertTrue(\in_array($option->getState('dataCenter'), ['fra-1', 'fra-2', 'lon-1']));

        $algo = new RoundRobin(-1);
        $option = $algo->run($options) ?? new Option([]);
        $this->assertEquals("fra-1", $option->getState('dataCenter'));
        $option = $algo->run($options) ?? new Option([]);
        $this->assertEquals("fra-2", $option->getState('dataCenter'));
        $option = $algo->run($options) ?? new Option([]);
        $this->assertEquals("lon-1", $option->getState('dataCenter'));
        $option = $algo->run($options) ?? new Option([]);
        $this->assertEquals("fra-1", $option->getState('dataCenter'));
        $option = $algo->run($options) ?? new Option([]);
        $this->assertEquals("fra-2", $option->getState('dataCenter'));
        $option = $algo->run($options) ?? new Option([]);
        $this->assertEquals("lon-1", $option->getState('dataCenter'));
        $option = $algo->run($options) ?? new Option([]);
        $this->assertEquals("fra-1", $option->getState('dataCenter'));

        $algo = new RoundRobin(1);
        $option = $algo->run($options) ?? new Option([]);
        $this->assertEquals("lon-1", $option->getState('dataCenter'));
        $algo->setIndex(0);
        $option = $algo->run($options) ?? new Option([]);
        $this->assertEquals("fra-2", $option->getState('dataCenter'));
    }

    public function testOption(): void
    {
        $option = new Option([]);

        $this->assertIsArray($option->getStates());
        $this->assertCount(0, $option->getStates());

        $this->assertFalse($option->getState("isOnline", false));
        $this->assertNull($option->getState("isOnline"));

        $option->setState("isOnline", true);

        $this->assertTrue($option->getState("isOnline", false));
        $this->assertTrue($option->getState("isOnline"));

        $this->assertNull($option->getState("ISONLINE"));

        $this->assertCount(1, $option->getStates());
        $this->assertEquals(true, $option->getStates()['isOnline']);

        $option
            ->setState('cpu', 50)
            ->setState('memory', 1800);

        $this->assertCount(3, $option->getStates());
        $this->assertEquals(50, $option->getStates()['cpu']);
        $this->assertEquals(1800, $option->getStates()['memory']);
        $this->assertTrue($option->getState("isOnline"));
        $this->assertEquals(50, $option->getState("cpu"));
        $this->assertEquals(1800, $option->getState("memory"));

        $option
            ->deleteState('isOnline')
            ->deleteState('cpu');

        $this->assertCount(1, $option->getStates());
        $this->assertNull($option->getState("isOnline"));
        $this->assertNull($option->getState("cpu"));
        $this->assertEquals(0, $option->getState("cpu", 0));
        $this->assertEquals(1800, $option->getState("memory"));
    }

    public function testGroup(): void
    {
        $options = [
            new Option([ 'dataCenter' => 'fra-1', 'cpu' => 91, 'online' => false ]),
            new Option([ 'dataCenter' => 'fra-2', 'cpu' => 95, 'online' => false ]),
            new Option([ 'dataCenter' => 'lon-1', 'cpu' => 87, 'online' => true ]),
        ];

        // Allow only online and low-cpu options
        $balancer1 = new Balancer(new First());
        $balancer1->addFilter(fn ($option) => $option->getState('cpu') < 80);
        $balancer1->addFilter(fn ($option) => $option->getState('online') === true);

        // Allow only online
        $balancer2 = new Balancer(new First());
        $balancer2->addFilter(fn ($option) => $option->getState('online') === true);

        // Allow anything
        $balancer3 = new Balancer(new First());

        foreach ($options as $option) {
            $balancer1->addOption($option);
            $balancer2->addOption($option);
            $balancer3->addOption($option);
        }

        $group = new Group();
        $group->add($balancer1);

        $this->assertNull($group->run());

        $group = new Group();
        $group
            ->add($balancer1)
            ->add($balancer2);

        $option = $group->run() ?? new Option([]);
        $this->assertEquals('lon-1', $option->getState('dataCenter'));

        $group = new Group();
        $group
            ->add($balancer1)
            ->add($balancer3);

        $option = $group->run() ?? new Option([]);
        $this->assertEquals('fra-1', $option->getState('dataCenter'));

        $group = new Group();
        $group
            ->add($balancer1)
            ->add($balancer2)
            ->add($balancer3);

        $option = $group->run() ?? new Option([]);
        $this->assertEquals('lon-1', $option->getState('dataCenter'));
    }

    public function testGetOptions(): void
    {
        $options = [
            new Option([ 'dataCenter' => 'fra-1', 'cpu' => 91, 'online' => false ]),
            new Option([ 'dataCenter' => 'fra-2', 'cpu' => 95, 'online' => false ]),
            new Option([ 'dataCenter' => 'lon-1', 'cpu' => 87, 'online' => true ]),
        ];

        $balancer1 = new Balancer(new First());
        $balancer2 = new Balancer(new First());

        foreach ($options as $option) {
            $balancer1->addOption($option);
            $balancer2->addOption($option);
        }

        $balancerOptions = $balancer1->getOptions();

        $this->assertCount(3, $balancerOptions);
        $this->assertEquals('fra-1', $balancerOptions[0]->getState('dataCenter'));
        $this->assertEquals('fra-2', $balancerOptions[1]->getState('dataCenter'));
        $this->assertEquals('lon-1', $balancerOptions[2]->getState('dataCenter'));

        $group = new Group();
        $group->add($balancer1);
        $group->add($balancer2);

        $groupOptions = $group->getOptions();

        $this->assertCount(6, $groupOptions);
        $this->assertEquals('fra-1', $groupOptions[0]->getState('dataCenter'));
        $this->assertEquals('fra-2', $groupOptions[1]->getState('dataCenter'));
        $this->assertEquals('lon-1', $groupOptions[2]->getState('dataCenter'));
        // Duplicates from second group also present
        $this->assertEquals('fra-1', $groupOptions[3]->getState('dataCenter'));
        $this->assertEquals('fra-2', $groupOptions[4]->getState('dataCenter'));
        $this->assertEquals('lon-1', $groupOptions[5]->getState('dataCenter'));
    }

    public function testFilteredOptions(): void
    {
        $balancer = new Balancer(new First());

        $balancer
            ->addOption(new Option(['hostname' => 'worker-1', 'isOnline' => true, 'cpu' => 80]))
            ->addOption(new Option(['hostname' => 'worker-2', 'isOnline' => false, 'cpu' => 20]))
            ->addOption(new Option(['hostname' => 'worker-3', 'isOnline' => true, 'cpu' => 35]));

        // Unfiltered, every option qualifies
        $this->assertCount(3, $balancer->getFilteredOptions());

        $balancer->addFilter(fn ($option) => $option->getState('isOnline') === true);

        $filtered = $balancer->getFilteredOptions();

        // All survivors, not just the one the algorithm would pick
        $this->assertCount(2, $filtered);
        $this->assertEquals('worker-1', $filtered[0]->getState('hostname'));
        $this->assertEquals('worker-3', $filtered[1]->getState('hostname'));
        $this->assertEquals('worker-1', ($balancer->run() ?? new Option([]))->getState('hostname'));

        $balancer->addFilter(fn ($option) => $option->getState('cpu') < 50);

        $filtered = $balancer->getFilteredOptions();

        // Filters compose, and the keys are reindexed from zero
        $this->assertCount(1, $filtered);
        $this->assertEquals('worker-3', $filtered[0]->getState('hostname'));

        $balancer->addFilter(fn ($option) => false);

        $this->assertSame([], $balancer->getFilteredOptions());
        $this->assertNull($balancer->run());
    }
}
