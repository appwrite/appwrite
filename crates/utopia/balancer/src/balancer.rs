//! [`Balancer`]: options, filters and the algorithm that chooses among them.

use std::sync::Arc;

use crate::{Algorithm, Error};

/// A filter: whether an option may be chosen.
pub type Filter<T> = Arc<dyn Fn(&T) -> bool + Send + Sync>;

/// Chooses one of its options with an [`Algorithm`], among those every
/// filter accepts.
///
/// Options are any type: [`Candidate`](crate::Candidate) for PHP's state
/// bags, or your own endpoint type. Filters run on every
/// [`Balancer::run`], so they see the options' current state.
///
/// PHP: `Utopia\Balancer\Balancer`.
///
/// ```
/// use utopia_balancer::{Balancer, Candidate, RoundRobin};
///
/// let mut balancer = Balancer::new(RoundRobin::new(-1));
/// balancer
///     .add_option(Candidate::from([("hostname", "worker-1".into()), ("online", true.into())]))
///     .add_option(Candidate::from([("hostname", "worker-2".into()), ("online", false.into())]))
///     .add_option(Candidate::from([("hostname", "worker-3".into()), ("online", true.into())]))
///     .add_filter(|c| c.get("online").is_some_and(|v| v.truthy()));
///
/// let host = |b: &mut Balancer<Candidate, RoundRobin>| b.run().unwrap().and_then(|c| c.get("hostname").cloned());
/// assert_eq!(host(&mut balancer), Some("worker-1".into()));
/// assert_eq!(host(&mut balancer), Some("worker-3".into()));
/// assert_eq!(host(&mut balancer), Some("worker-1".into()));
/// ```
///
/// ```php
/// use Utopia\Balancer\Algorithm\RoundRobin;
/// use Utopia\Balancer\Balancer;
/// use Utopia\Balancer\Option;
///
/// $balancer = new Balancer(new RoundRobin(-1));
/// $balancer
///     ->addOption(new Option(['hostname' => 'worker-1', 'online' => true]))
///     ->addOption(new Option(['hostname' => 'worker-2', 'online' => false]))
///     ->addOption(new Option(['hostname' => 'worker-3', 'online' => true]))
///     ->addFilter(fn (Option $o) => $o->getState('online') === true);
///
/// echo $balancer->run()->getState('hostname'), "\n"; // worker-1
/// echo $balancer->run()->getState('hostname'), "\n"; // worker-3
/// echo $balancer->run()->getState('hostname'), "\n"; // worker-1
/// ```
pub struct Balancer<T, A> {
    algorithm: A,
    options: Vec<T>,
    filters: Vec<Filter<T>>,
}

impl<T, A: Algorithm<T>> Balancer<T, A> {
    /// A balancer without options, choosing with `algorithm`.
    ///
    /// PHP: `new Balancer($algo)`.
    pub fn new(algorithm: A) -> Self {
        Balancer { algorithm, options: Vec::new(), filters: Vec::new() }
    }

    /// The algorithm.
    ///
    /// PHP: `getAlgo()`.
    pub fn algorithm(&self) -> &A {
        &self.algorithm
    }

    /// The algorithm, to change its state (such as a
    /// [`RoundRobin`](crate::RoundRobin) index).
    ///
    /// PHP: `getAlgo()`, which returns the object itself.
    pub fn algorithm_mut(&mut self) -> &mut A {
        &mut self.algorithm
    }

    /// Adds an option after the others.
    ///
    /// PHP: `addOption($option)`.
    pub fn add_option(&mut self, option: T) -> &mut Self {
        self.options.push(option);
        self
    }

    /// Every option, in the order they were added, filtered or not.
    ///
    /// PHP: `getOptions()`.
    pub fn options(&self) -> &[T] {
        &self.options
    }

    /// Adds a filter: only options every filter accepts can be chosen.
    ///
    /// PHP: `addFilter($callable)`.
    pub fn add_filter(&mut self, filter: impl Fn(&T) -> bool + Send + Sync + 'static) -> &mut Self {
        self.filters.push(Arc::new(filter));
        self
    }

    /// Every option every filter accepts, in the order they were added: what
    /// the algorithm chooses from, for callers that act on all of them.
    ///
    /// PHP: `getFilteredOptions()`.
    pub fn filtered(&self) -> Vec<&T> {
        self.options.iter().filter(|o| self.filters.iter().all(|f| f(o))).collect()
    }

    /// The position in [`Balancer::options`] of the option the algorithm
    /// chooses among the filtered ones, or `None` when none passes or the
    /// algorithm chooses nothing.
    ///
    /// PHP: `run()`, as a position.
    pub fn pick(&mut self) -> Result<Option<usize>, Error> {
        let positions: Vec<usize> =
            (0..self.options.len()).filter(|&i| self.filters.iter().all(|f| f(&self.options[i]))).collect();
        if positions.is_empty() {
            return Ok(None);
        }
        let options: Vec<&T> = positions.iter().map(|&i| &self.options[i]).collect();
        Ok(self.algorithm.pick(&options)?.and_then(|p| positions.get(p).copied()))
    }

    /// The option the algorithm chooses among the filtered ones, or `None`
    /// when none passes or the algorithm chooses nothing. The algorithm is
    /// not consulted without options.
    ///
    /// PHP: `run()`.
    pub fn run(&mut self) -> Result<Option<&T>, Error> {
        Ok(self.pick()?.map(|i| &self.options[i]))
    }
}

impl<T: Clone, A: Clone> Clone for Balancer<T, A> {
    /// A copy with cloned options and algorithm, sharing the filters.
    fn clone(&self) -> Self {
        Balancer { algorithm: self.algorithm.clone(), options: self.options.clone(), filters: self.filters.clone() }
    }
}

impl<T, A: Algorithm<T>> std::fmt::Debug for Balancer<T, A> {
    /// The algorithm's name and the number of options and filters.
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Balancer")
            .field("algorithm", &self.algorithm.name())
            .field("options", &self.options.len())
            .field("filters", &self.filters.len())
            .finish()
    }
}
