//! [`Group`]: balancers tried in order until one chooses an option.

use std::time::Instant;

use utopia_telemetry::{Histogram, NoTelemetry, Telemetry, Value};

use crate::{Algorithm, Balancer, Error};

/// The bucket boundaries of `balancer.run.duration`, in seconds.
const BOUNDARIES: &[f64] = &[0.005, 0.01, 0.025, 0.05, 0.075, 0.1, 0.25, 0.5, 0.75, 1.0, 2.5, 5.0, 7.5, 10.0];

/// Balancers tried in the order they were added: the first that chooses an
/// option wins. Use it for fallbacks, such as preferring healthy and idle
/// workers, then any healthy one, then anything.
///
/// Every [`Group::run`] records its duration in seconds on the
/// `balancer.run.duration` histogram, with the group's name as the `name`
/// attribute.
///
/// PHP: `Utopia\Balancer\Group`.
///
/// ```
/// use utopia_balancer::{Balancer, Candidate, First, Group};
///
/// let options = [
///     Candidate::from([("dc", "fra-1".into()), ("online", false.into())]),
///     Candidate::from([("dc", "lon-1".into()), ("online", true.into())]),
/// ];
///
/// let mut online = Balancer::new(First);
/// online.add_filter(|c: &Candidate| c.get("online").is_some_and(|v| v.truthy()));
/// let mut any = Balancer::new(First);
/// for option in &options {
///     online.add_option(option.clone());
///     any.add_option(option.clone());
/// }
///
/// let mut group = Group::new(Some("workers"));
/// group.add(online).add(any);
/// assert_eq!(group.run()?.and_then(|c| c.get("dc")), Some(&"lon-1".into()));
/// # Ok::<(), utopia_balancer::Error>(())
/// ```
///
/// ```php
/// use Utopia\Balancer\Algorithm\First;
/// use Utopia\Balancer\Balancer;
/// use Utopia\Balancer\Group;
/// use Utopia\Balancer\Option;
///
/// $options = [new Option(['dc' => 'fra-1', 'online' => false]), new Option(['dc' => 'lon-1', 'online' => true])];
///
/// $online = (new Balancer(new First()))->addFilter(fn (Option $o) => $o->getState('online') === true);
/// $any = new Balancer(new First());
/// foreach ($options as $option) {
///     $online->addOption($option);
///     $any->addOption($option);
/// }
///
/// $group = new Group('workers');
/// $group->add($online)->add($any);
/// echo $group->run()->getState('dc'), "\n"; // lon-1
/// ```
pub struct Group<T, A> {
    name: Option<String>,
    balancers: Vec<Balancer<T, A>>,
    duration: Histogram,
}

impl<T, A: Algorithm<T>> Group<T, A> {
    /// An empty group, recording no telemetry.
    ///
    /// PHP: `new Group($name)`.
    pub fn new(name: Option<&str>) -> Self {
        Group { name: name.map(str::to_owned), balancers: Vec::new(), duration: histogram(&NoTelemetry) }
    }

    /// Records `balancer.run.duration` on `telemetry` from now on.
    ///
    /// PHP: `setTelemetry($telemetry)`.
    pub fn set_telemetry(&mut self, telemetry: &dyn Telemetry) -> &mut Self {
        self.duration = histogram(telemetry);
        self
    }

    /// Adds a balancer, tried after the others.
    ///
    /// PHP: `add($balancer)`.
    pub fn add(&mut self, balancer: Balancer<T, A>) -> &mut Self {
        self.balancers.push(balancer);
        self
    }

    /// The balancers, in the order they are tried.
    pub fn balancers(&self) -> &[Balancer<T, A>] {
        &self.balancers
    }

    /// The balancers, to change them after they were added.
    pub fn balancers_mut(&mut self) -> &mut [Balancer<T, A>] {
        &mut self.balancers
    }

    /// Every option of every balancer, balancer by balancer, unfiltered: an
    /// option added to two balancers appears twice.
    ///
    /// PHP: `getOptions()`.
    pub fn options(&self) -> Vec<&T> {
        self.balancers.iter().flat_map(|b| b.options()).collect()
    }

    /// The option of the first balancer that chooses one, or `None` when none
    /// does. An algorithm's error stops the run, and nothing is recorded.
    ///
    /// PHP: `run()`.
    pub fn run(&mut self) -> Result<Option<&T>, Error> {
        let started = Instant::now();
        let mut chosen = None;
        for (b, balancer) in self.balancers.iter_mut().enumerate() {
            if let Some(i) = balancer.pick()? {
                chosen = Some((b, i));
                break;
            }
        }
        let elapsed = started.elapsed().as_secs_f64();
        match &self.name {
            Some(name) => self.duration.record(elapsed, &[("name", Value::from(name))]),
            None => self.duration.record(elapsed, &[]),
        }
        Ok(chosen.map(|(b, i)| &self.balancers[b].options()[i]))
    }
}

fn histogram(telemetry: &dyn Telemetry) -> Histogram {
    telemetry.histogram("balancer.run.duration", Some("s"), None, Some(BOUNDARIES))
}

impl<T, A: Algorithm<T>> std::fmt::Debug for Group<T, A> {
    /// The name and the balancers.
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Group").field("name", &self.name).field("balancers", &self.balancers).finish()
    }
}
