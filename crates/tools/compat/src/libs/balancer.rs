//! Compat adapter for `balancer`: maps `tests/compat/balancer/spec.json` operations onto `utopia-balancer`.
//!
//! PHP shares `Option`, algorithm and balancer objects by reference, so the
//! options here are `Arc<Mutex<Candidate>>` and the algorithms a shared
//! handle; a balancer added to a group is a clone sharing both. Filters are
//! data (`{key, op, value}`) compared with php-std's operators, as
//! Adapter.php compares them with PHP's.

use std::sync::{Arc, Mutex};

use php_std::Value as Php;
use serde_json::{Value, json};
use utopia_balancer::{Algorithm, Balancer, Candidate, Error, First, Group, Last, Random, RoundRobin};
use utopia_telemetry::{Kind, MemoryTelemetry};

use crate::adapter::{Args, Fault, OpResult, Outcome, Session};

pub const OPS: &[&str] = &[
    "option.new",
    "option.get_state",
    "option.set_state",
    "option.delete_state",
    "option.get_states",
    "option.scenario",
    "algorithm.new",
    "algorithm.name",
    "algorithm.run",
    "round_robin.get_index",
    "round_robin.set_index",
    "balancer.new",
    "balancer.get_algo",
    "balancer.add_option",
    "balancer.add_filter",
    "balancer.get_options",
    "balancer.get_filtered_options",
    "balancer.run",
    "balancer.scenario",
    "group.new",
    "group.add",
    "group.get_options",
    "group.run",
    "group.set_telemetry",
    "telemetry.new",
    "telemetry.dump",
];

/// Benchmarks (same names and work as `benchmarks()` in Adapter.php).
pub const BENCHES: &[(&str, &str)] = &[
    ("round_robin", "Choose the next of 8 options with RoundRobin"),
    ("filtered", "Choose the first of 8 options passing two filters (online, cpu < 50)"),
    ("random", "Choose a random one of 8 options"),
    ("group", "Run a group of a filtered balancer that chooses nothing and a RoundRobin fallback"),
    ("option_state", "Read a state value of an option with three values"),
];

pub async fn bench(name: &str, _session: &mut Session) -> Result<crate::bench::Bench, Fault> {
    use std::hint::black_box;

    use crate::bench::Bench;

    fn workers() -> Vec<Candidate> {
        (1..=8)
            .map(|i: i64| {
                Candidate::from([
                    ("hostname", Php::from(format!("worker-{i}").as_str())),
                    ("online", Php::from(i % 3 != 0)),
                    ("cpu", Php::from((i * 17) % 100)),
                ])
            })
            .collect()
    }
    fn balancer<A: Algorithm<Candidate>>(algorithm: A, filtered: bool) -> Balancer<Candidate, A> {
        let mut b = Balancer::new(algorithm);
        for option in workers() {
            b.add_option(option);
        }
        if filtered {
            b.add_filter(|o| o.get("online").is_some_and(|v| v.strict_eq(&Php::Bool(true))));
            b.add_filter(|o| o.get("cpu").unwrap_or(&Php::Null).compare(&Php::Int(50)) < 0);
        }
        b
    }

    Ok(match name {
        "round_robin" => {
            let mut b = balancer(RoundRobin::new(-1), false);
            Bench::sync(move || {
                black_box(b.run().ok().flatten());
            })
        }
        "filtered" => {
            let mut b = balancer(First, true);
            Bench::sync(move || {
                black_box(b.run().ok().flatten());
            })
        }
        "random" => {
            let mut b = balancer(Random::seeded(1), false);
            Bench::sync(move || {
                black_box(b.run().ok().flatten());
            })
        }
        "group" => {
            let mut none: Balancer<Candidate, Box<dyn Algorithm<Candidate> + Send>> = Balancer::new(Box::new(First));
            for option in workers() {
                none.add_option(option);
            }
            none.add_filter(|o| o.get("online").is_some_and(|v| v.strict_eq(&Php::Bool(true))));
            none.add_filter(|o| o.get("cpu").unwrap_or(&Php::Null).compare(&Php::Int(50)) < 0);
            none.add_filter(|_| false);
            let mut fallback: Balancer<Candidate, Box<dyn Algorithm<Candidate> + Send>> =
                Balancer::new(Box::new(RoundRobin::new(-1)));
            for option in workers() {
                fallback.add_option(option);
            }
            let mut group = Group::new(Some("bench"));
            group.add(none).add(fallback);
            Bench::sync(move || {
                black_box(group.run().ok().flatten());
            })
        }
        "option_state" => {
            let option =
                Candidate::from([("hostname", "worker-1".into()), ("online", true.into()), ("cpu", 35.into())]);
            let zero = Php::Int(0);
            Bench::sync(move || {
                black_box(black_box(&option).get_or(black_box("cpu"), &zero));
            })
        }
        _ => return Err(Fault::new(format!("balancer: no benchmark `{name}`"))),
    })
}

/// An option, shared like a PHP object.
type Shared = Arc<Mutex<Candidate>>;

/// The algorithms, as one type a balancer can hold.
enum Any {
    First(First),
    Last(Last),
    Random(Box<Random>),
    RoundRobin(RoundRobin),
}

/// An algorithm, shared like a PHP object between its handle and the
/// balancers using it.
#[derive(Clone)]
struct Algo(Arc<Mutex<Any>>);

impl Algorithm<Shared> for Algo {
    fn name(&self) -> &str {
        match &*lock(&self.0) {
            Any::First(_) => "First",
            Any::Last(_) => "Last",
            Any::Random(_) => "Random",
            Any::RoundRobin(_) => "RoundRobin",
        }
    }

    fn pick(&mut self, options: &[&Shared]) -> Result<Option<usize>, Error> {
        match &mut *lock(&self.0) {
            Any::First(a) => a.pick(options),
            Any::Last(a) => a.pick(options),
            Any::Random(a) => a.pick(options),
            Any::RoundRobin(a) => a.pick(options),
        }
    }
}

type Bal = Balancer<Shared, Algo>;

fn lock<T>(m: &Arc<Mutex<T>>) -> std::sync::MutexGuard<'_, T> {
    m.lock().unwrap_or_else(|e| e.into_inner())
}

fn err(e: Error) -> Outcome {
    Outcome::err(e.php_class(), e.to_string())
}

fn json(v: &Php) -> Result<Value, Fault> {
    v.try_to_json().ok_or_else(|| Fault::new("balancer: a value JSON cannot hold"))
}

fn state(v: Option<&Value>) -> php_std::Array {
    match v.map(Php::from_json) {
        Some(Php::Array(a)) => a,
        _ => php_std::Array::new(),
    }
}

fn states<'a>(options: impl IntoIterator<Item = &'a Shared>) -> Result<Value, Fault> {
    options
        .into_iter()
        .map(|o| json(&Php::Array(lock(o).states().clone())))
        .collect::<Result<Vec<_>, _>>()
        .map(Value::Array)
}

fn chosen(o: Option<&Shared>) -> Result<Value, Fault> {
    match o {
        Some(o) => json(&Php::Array(lock(o).states().clone())),
        None => Ok(Value::Null),
    }
}

fn algorithm(a: &Args) -> Result<Any, Fault> {
    Ok(match a.str("type")? {
        "First" => Any::First(First),
        "Last" => Any::Last(Last),
        "Random" => Any::Random(Box::new(match a.opt_i64("seed")? {
            Some(seed) => Random::seeded(seed),
            None => Random::new(),
        })),
        "RoundRobin" => Any::RoundRobin(RoundRobin::new(a.i64("index")?)),
        other => return Err(Fault::new(format!("unknown algorithm {other}"))),
    })
}

/// A filter from `{key, op, value}`, with PHP's operator semantics.
fn filter(f: &Value) -> Result<impl Fn(&Shared) -> bool + Send + Sync + 'static, Fault> {
    let a = Args(f);
    let key = a.opt_str("key")?.unwrap_or("").to_owned();
    let value = a.opt("value").map(Php::from_json).unwrap_or(Php::Null);
    let op = a.str("op")?.to_owned();
    if !["===", "!==", "==", "<", ">", "truthy", "false"].contains(&op.as_str()) {
        return Err(Fault::new(format!("unknown filter op {op}")));
    }
    Ok(move |o: &Shared| {
        let o = lock(o);
        let state = o.get(&key).unwrap_or(&Php::Null);
        match op.as_str() {
            "===" => state.strict_eq(&value),
            "!==" => !state.strict_eq(&value),
            "==" => state.loose_eq(&value),
            "<" => state.compare(&value) < 0,
            ">" => value.compare(state) < 0,
            "truthy" => state.truthy(),
            _ => false,
        }
    })
}

pub async fn call(op: &str, args: &Value, session: &mut Session) -> OpResult {
    let a = Args(args);
    let option = |s: &Session, key: &str| -> Result<Shared, Fault> { s.get::<Shared>(a.value(key)?).cloned() };
    let algo = |s: &Session| -> Result<Algo, Fault> { s.get::<Algo>(a.value("algorithm")?).cloned() };
    let bal =
        |s: &Session| -> Result<Arc<Mutex<Bal>>, Fault> { s.get::<Arc<Mutex<Bal>>>(a.value("balancer")?).cloned() };
    let group = |s: &Session| -> Result<Arc<Mutex<Group<Shared, Algo>>>, Fault> {
        s.get::<Arc<Mutex<Group<Shared, Algo>>>>(a.value("group")?).cloned()
    };
    let result = match op {
        "option.new" => {
            Outcome::Ok(session.handle::<Shared>(Arc::new(Mutex::new(Candidate::new(state(a.opt("state")))))))
        }
        "option.get_state" => {
            let o = option(session, "option")?;
            let o = lock(&o);
            let default = a.opt("default").map(Php::from_json).unwrap_or(Php::Null);
            Outcome::Ok(json(o.get_or(a.str("key")?, &default))?)
        }
        "option.set_state" => {
            let value = a.opt("value").map(Php::from_json).unwrap_or(Php::Null);
            lock(&option(session, "option")?).set(a.str("key")?, value);
            Outcome::Ok(Value::Null)
        }
        "option.delete_state" => {
            lock(&option(session, "option")?).remove(a.str("key")?);
            Outcome::Ok(Value::Null)
        }
        "option.get_states" => Outcome::Ok(json(&Php::Array(lock(&option(session, "option")?).states().clone()))?),
        "option.scenario" => {
            let mut o = Candidate::new(state(a.opt("state")));
            for pair in a.opt("set").and_then(Value::as_array).into_iter().flatten() {
                let p = Args(pair);
                o.set(p.str("k")?, p.opt("v").map(Php::from_json).unwrap_or(Php::Null));
            }
            for key in a.opt("delete").and_then(Value::as_array).into_iter().flatten() {
                o.remove(key.as_str().ok_or_else(|| Fault::new("delete keys are strings"))?);
            }
            let default = a.opt("default").map(Php::from_json).unwrap_or(Php::Null);
            let mut gets = Vec::new();
            for key in a.opt("get").and_then(Value::as_array).into_iter().flatten() {
                gets.push(json(o.get_or(key.as_str().ok_or_else(|| Fault::new("get keys are strings"))?, &default))?);
            }
            Outcome::Ok(json!({ "states": json(&Php::Array(o.states().clone()))?, "gets": gets }))
        }
        "algorithm.new" => Outcome::Ok(session.handle(Algo(Arc::new(Mutex::new(algorithm(&a)?))))),
        "algorithm.name" => Outcome::ok(algo(session)?.name()),
        "algorithm.run" => {
            let options = a
                .opt("options")
                .and_then(Value::as_array)
                .map(|l| l.iter().map(|h| session.get::<Shared>(h).cloned()).collect::<Result<Vec<_>, _>>())
                .transpose()?
                .unwrap_or_default();
            let refs: Vec<&Shared> = options.iter().collect();
            match algo(session)?.pick(&refs) {
                Ok(p) => Outcome::Ok(p.map_or(Value::Null, Value::from)),
                Err(e) => err(e),
            }
        }
        "round_robin.get_index" => match &*lock(&algo(session)?.0) {
            Any::RoundRobin(r) => Outcome::ok(r.index()),
            _ => return Err(Fault::new("not a round robin")),
        },
        "round_robin.set_index" => match &mut *lock(&algo(session)?.0) {
            Any::RoundRobin(r) => {
                r.set_index(a.i64("index")?);
                Outcome::Ok(Value::Null)
            }
            _ => return Err(Fault::new("not a round robin")),
        },
        "balancer.new" => {
            let b: Bal = Balancer::new(algo(session)?);
            Outcome::Ok(session.handle(Arc::new(Mutex::new(b))))
        }
        "balancer.get_algo" => Outcome::ok(lock(&bal(session)?).algorithm().name()),
        "balancer.add_option" => {
            let o = option(session, "option")?;
            lock(&bal(session)?).add_option(o);
            Outcome::Ok(Value::Null)
        }
        "balancer.add_filter" => {
            let f = filter(a.value("filter")?)?;
            lock(&bal(session)?).add_filter(f);
            Outcome::Ok(Value::Null)
        }
        "balancer.get_options" => Outcome::Ok(states(lock(&bal(session)?).options())?),
        "balancer.get_filtered_options" => Outcome::Ok(states(lock(&bal(session)?).filtered())?),
        "balancer.run" => {
            let b = bal(session)?;
            let mut b = lock(&b);
            match b.run() {
                Ok(o) => Outcome::Ok(chosen(o)?),
                Err(e) => err(e),
            }
        }
        "balancer.scenario" => {
            let mut b: Bal = Balancer::new(Algo(Arc::new(Mutex::new(algorithm(&Args(a.value("algorithm")?))?))));
            for s in a.opt("options").and_then(Value::as_array).into_iter().flatten() {
                b.add_option(Arc::new(Mutex::new(Candidate::new(state(Some(s))))));
            }
            for f in a.opt("filters").and_then(Value::as_array).into_iter().flatten() {
                b.add_filter(filter(f)?);
            }
            let mut picks = Vec::new();
            for _ in 0..a.i64("runs")? {
                match b.pick() {
                    Ok(p) => picks.push(p.map_or(Value::Null, Value::from)),
                    Err(e) => return Ok(err(e)),
                }
            }
            Outcome::Ok(Value::Array(picks))
        }
        "group.new" => {
            let g: Group<Shared, Algo> = Group::new(a.opt_str("name")?);
            Outcome::Ok(session.handle(Arc::new(Mutex::new(g))))
        }
        "group.add" => {
            let b = lock(&bal(session)?).clone();
            lock(&group(session)?).add(b);
            Outcome::Ok(Value::Null)
        }
        "group.get_options" => Outcome::Ok(states(lock(&group(session)?).options())?),
        "group.run" => {
            let g = group(session)?;
            let mut g = lock(&g);
            match g.run() {
                Ok(o) => Outcome::Ok(chosen(o)?),
                Err(e) => err(e),
            }
        }
        "group.set_telemetry" => {
            let t = session.get::<MemoryTelemetry>(a.value("telemetry")?)?.clone();
            lock(&group(session)?).set_telemetry(&t);
            Outcome::Ok(Value::Null)
        }
        "telemetry.new" => Outcome::Ok(session.handle(MemoryTelemetry::new())),
        "telemetry.dump" => {
            let t = session.get::<MemoryTelemetry>(a.value("telemetry")?)?;
            Outcome::Ok(Value::Array(
                t.names(Kind::Histogram)
                    .into_iter()
                    .map(|n| {
                        let c = t.values(Kind::Histogram, &n).map_or(0, |v| v.len());
                        json!([n, c])
                    })
                    .collect(),
            ))
        }
        _ => return Err(Fault::new(format!("balancer: unknown operation `{op}`"))),
    };
    Ok(result)
}
