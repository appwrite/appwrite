# Utopia Balancer

> [!IMPORTANT]
> This repository is a read-only mirror of [`crates/utopia/balancer`](https://github.com/appwrite/appwrite/tree/main/crates/utopia/balancer) in [appwrite/appwrite](https://github.com/appwrite/appwrite). Development happens there — please open issues and pull requests against appwrite/appwrite.

[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://discord.gg/GSeTUeA)

Choosing between endpoints: options with state, filters, the First, Last, Random and RoundRobin algorithms, and groups of balancers tried in order.

This library is maintained by the [Appwrite team](https://appwrite.io).

## Getting started

```toml
[dependencies]
utopia-balancer = { git = "https://github.com/utopia-rs/balancer" }
```

A balancer picks one endpoint out of many: a worker for the next job, a region for the next request. You give it options, filters that rule out the options that cannot serve right now, and an algorithm that chooses among the rest. Options can be any type; `Candidate` is a ready-made one holding named values such as a hostname or a CPU load. This guide covers the common tasks.

## Rotate between options

`RoundRobin` hands out each option in turn and starts over after the last. Start it at `-1` to begin with the first option.

```rust
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
```

## Skip options that cannot serve

Filters decide which options the algorithm may choose. An option must pass every filter. `filtered` lists all the options that pass, for when you act on every one of them rather than choosing.

```rust
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
```

## Fall back when nothing qualifies

A `Group` tries its balancers in order and returns the first option one of them chooses: prefer idle workers, then any online one, then anything.

```rust
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
```

## Reproduce random choices

`Random` picks uniformly with PHP's Mersenne Twister. Seed it to replay a sequence in a test: `Random::seeded(7)` picks what PHP picks after `mt_srand(7)`.

```rust
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
```

## Balance your own endpoint type

Options do not have to be `Candidate`s. Use your own type, and filters and custom algorithms see it directly.

```rust
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
```

## Contributing

Development happens in [appwrite/appwrite](https://github.com/appwrite/appwrite), where this crate lives at [`crates/utopia/balancer`](https://github.com/appwrite/appwrite/tree/main/crates/utopia/balancer). Run these commands from a checkout of it. The [contributing guide](https://utopia-docs.appwrite.network/contributing) covers each step.

### Test

```bash
cargo test -p utopia-balancer
cargo clippy -p utopia-balancer --all-targets -- -D warnings
cargo fmt --all --check
```

### Compare with PHP

Replay the results PHP recorded for every case against Rust, without PHP:

```bash
COMPAT_LIB=balancer cargo test -p compat
```

Run every case on both runtimes, fuzz both with generated inputs, then run the whole conversion check. These need the dev stack (`docker compose up -d`):

```bash
bin/compat run balancer
bin/compat fuzz balancer
bin/compat check balancer
```

### Benchmark

```bash
bin/compat bench balancer
```

This times every benchmark on PHP and on a release build of Rust, both in containers on the dev stack. It fails if Rust is slower. Add `--write` to record the run in [`tests/compat/balancer/bench.json`](https://github.com/appwrite/appwrite/blob/main/tests/compat/balancer/bench.json). Run it on a quiet machine, one library at a time.

## License

BSD-3-Clause. See [LICENSE](LICENSE).
