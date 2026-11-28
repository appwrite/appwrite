A balancer picks one endpoint out of many: a worker for the next job, a region for the next request. You give it options, filters that rule out the options that cannot serve right now, and an algorithm that chooses among the rest. Options can be any type; `Candidate` is a ready-made one holding named values such as a hostname or a CPU load. This guide covers the common tasks.

## Rotate between options

`RoundRobin` hands out each option in turn and starts over after the last. Start it at `-1` to begin with the first option.

```
use utopia_balancer::{Balancer, Candidate, RoundRobin};

let mut balancer = Balancer::new(RoundRobin::new(-1));
for host in ["worker-1", "worker-2", "worker-3"] {
    balancer.add_option(Candidate::from([("hostname", host.into())]));
}

let mut picks = Vec::new();
for _ in 0..4 {
    let option = balancer.run()?.expect("an option");
    picks.push(option.get("hostname").cloned());
}
assert_eq!(picks, [Some("worker-1".into()), Some("worker-2".into()), Some("worker-3".into()), Some("worker-1".into())]);
# Ok::<(), utopia_balancer::Error>(())
```

```php
use Utopia\Balancer\Algorithm\RoundRobin;
use Utopia\Balancer\Balancer;
use Utopia\Balancer\Option;

$balancer = new Balancer(new RoundRobin(-1));
foreach (['worker-1', 'worker-2', 'worker-3'] as $host) {
    $balancer->addOption(new Option(['hostname' => $host]));
}

foreach ([1, 2, 3, 4] as $_) {
    echo $balancer->run()->getState('hostname'), "\n"; // worker-1, worker-2, worker-3, worker-1
}
```

## Skip options that cannot serve

Filters decide which options the algorithm may choose. An option must pass every filter. `filtered` lists all the options that pass, for when you act on every one of them rather than choosing.

```
use utopia_balancer::{Balancer, Candidate, First};

let mut balancer = Balancer::new(First);
balancer
    .add_option(Candidate::from([("hostname", "worker-1".into()), ("online", true.into()), ("cpu", 80.into())]))
    .add_option(Candidate::from([("hostname", "worker-2".into()), ("online", false.into()), ("cpu", 20.into())]))
    .add_option(Candidate::from([("hostname", "worker-3".into()), ("online", true.into()), ("cpu", 35.into())]))
    .add_filter(|c| c.get("online").is_some_and(|v| v.truthy()));

assert_eq!(balancer.filtered().len(), 2);

balancer.add_filter(|c| c.get("cpu").is_some_and(|v| v.to_double() < 50.0));
assert_eq!(balancer.run()?.and_then(|c| c.get("hostname")), Some(&"worker-3".into()));
# Ok::<(), utopia_balancer::Error>(())
```

```php
use Utopia\Balancer\Algorithm\First;
use Utopia\Balancer\Balancer;
use Utopia\Balancer\Option;

$balancer = new Balancer(new First());
$balancer
    ->addOption(new Option(['hostname' => 'worker-1', 'online' => true, 'cpu' => 80]))
    ->addOption(new Option(['hostname' => 'worker-2', 'online' => false, 'cpu' => 20]))
    ->addOption(new Option(['hostname' => 'worker-3', 'online' => true, 'cpu' => 35]))
    ->addFilter(fn (Option $o) => $o->getState('online') === true);

echo \count($balancer->getFilteredOptions()), "\n"; // 2

$balancer->addFilter(fn (Option $o) => $o->getState('cpu') < 50);
echo $balancer->run()->getState('hostname'), "\n"; // worker-3
```

## Fall back when nothing qualifies

A `Group` tries its balancers in order and returns the first option one of them chooses: prefer idle workers, then any online one, then anything.

```
use utopia_balancer::{Balancer, Candidate, First, Group};

let options = [
    Candidate::from([("dc", "fra-1".into()), ("cpu", 91.into()), ("online", false.into())]),
    Candidate::from([("dc", "lon-1".into()), ("cpu", 87.into()), ("online", true.into())]),
];

let mut idle = Balancer::new(First);
idle.add_filter(|c: &Candidate| c.get("cpu").is_some_and(|v| v.to_double() < 80.0));
let mut online = Balancer::new(First);
online.add_filter(|c: &Candidate| c.get("online").is_some_and(|v| v.truthy()));
for option in &options {
    idle.add_option(option.clone());
    online.add_option(option.clone());
}

let mut group = Group::new(None);
group.add(idle).add(online);
assert_eq!(group.run()?.and_then(|c| c.get("dc")), Some(&"lon-1".into()));
# Ok::<(), utopia_balancer::Error>(())
```

```php
use Utopia\Balancer\Algorithm\First;
use Utopia\Balancer\Balancer;
use Utopia\Balancer\Group;
use Utopia\Balancer\Option;

$options = [
    new Option(['dc' => 'fra-1', 'cpu' => 91, 'online' => false]),
    new Option(['dc' => 'lon-1', 'cpu' => 87, 'online' => true]),
];

$idle = (new Balancer(new First()))->addFilter(fn (Option $o) => $o->getState('cpu') < 80);
$online = (new Balancer(new First()))->addFilter(fn (Option $o) => $o->getState('online') === true);
foreach ($options as $option) {
    $idle->addOption($option);
    $online->addOption($option);
}

$group = new Group();
$group->add($idle)->add($online);
echo $group->run()->getState('dc'), "\n"; // lon-1
```

## Reproduce random choices

`Random` picks uniformly with PHP's Mersenne Twister. Seed it to replay a sequence in a test: `Random::seeded(7)` picks what PHP picks after `mt_srand(7)`.

```
use utopia_balancer::{Balancer, Candidate, Random};

let mut balancer = Balancer::new(Random::seeded(7));
for dc in ["fra-1", "fra-2", "lon-1"] {
    balancer.add_option(Candidate::from([("dc", dc.into())]));
}

let mut picks = Vec::new();
for _ in 0..3 {
    picks.push(balancer.run()?.and_then(|c| c.get("dc")).cloned());
}
assert_eq!(picks, [Some("fra-1".into()), Some("fra-2".into()), Some("fra-2".into())]);
# Ok::<(), utopia_balancer::Error>(())
```

```php
use Utopia\Balancer\Algorithm\Random;
use Utopia\Balancer\Balancer;
use Utopia\Balancer\Option;

mt_srand(7);
$balancer = new Balancer(new Random());
foreach (['fra-1', 'fra-2', 'lon-1'] as $dc) {
    $balancer->addOption(new Option(['dc' => $dc]));
}

foreach ([1, 2, 3] as $_) {
    echo $balancer->run()->getState('dc'), "\n"; // fra-1, fra-2, fra-2
}
```

## Balance your own endpoint type

Options do not have to be `Candidate`s. Use your own type, and filters and custom algorithms see it directly.

```
use utopia_balancer::{Balancer, Last};

struct Upstream {
    url: &'static str,
    healthy: bool,
}

let mut balancer = Balancer::new(Last);
balancer
    .add_option(Upstream { url: "http://10.0.0.1", healthy: true })
    .add_option(Upstream { url: "http://10.0.0.2", healthy: true })
    .add_option(Upstream { url: "http://10.0.0.3", healthy: false })
    .add_filter(|u| u.healthy);

assert_eq!(balancer.run()?.map(|u| u.url), Some("http://10.0.0.2"));
# Ok::<(), utopia_balancer::Error>(())
```

```php
use Utopia\Balancer\Algorithm\Last;
use Utopia\Balancer\Balancer;
use Utopia\Balancer\Option;

$balancer = new Balancer(new Last());
$balancer
    ->addOption(new Option(['url' => 'http://10.0.0.1', 'healthy' => true]))
    ->addOption(new Option(['url' => 'http://10.0.0.2', 'healthy' => true]))
    ->addOption(new Option(['url' => 'http://10.0.0.3', 'healthy' => false]))
    ->addFilter(fn (Option $o) => $o->getState('healthy'));

echo $balancer->run()->getState('url'), "\n"; // http://10.0.0.2
```
