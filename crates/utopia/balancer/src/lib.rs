//! Choosing between endpoints: keep a list of options (workers, regions,
//! upstream servers), filter out the ones that cannot serve right now, and let
//! an algorithm choose one of the rest.
//!
//! A [`Balancer`] holds options of any type, [filters](Balancer::add_filter)
//! and an [`Algorithm`]: [`First`], [`Last`], [`Random`] or [`RoundRobin`], or
//! your own. A [`Group`] tries several balancers in order, for fallbacks.
//! [`Candidate`] is a ready-made option: a bag of named values such as
//! `hostname`, `online` or `cpu`.
//!
//! ```
//! use utopia_balancer::{Balancer, Candidate, First};
//!
//! let mut balancer = Balancer::new(First);
//! balancer
//!     .add_option(Candidate::from([("dc", "fra-1".into()), ("cpu", 91.into())]))
//!     .add_option(Candidate::from([("dc", "lon-1".into()), ("cpu", 35.into())]))
//!     .add_filter(|c| c.get("cpu").is_some_and(|cpu| cpu.to_double() < 50.0));
//!
//! assert_eq!(balancer.run()?.and_then(|c| c.get("dc")), Some(&"lon-1".into()));
//! # Ok::<(), utopia_balancer::Error>(())
//! ```
//!
//! ```php
//! use Utopia\Balancer\Algorithm\First;
//! use Utopia\Balancer\Balancer;
//! use Utopia\Balancer\Option;
//!
//! $balancer = new Balancer(new First());
//! $balancer
//!     ->addOption(new Option(['dc' => 'fra-1', 'cpu' => 91]))
//!     ->addOption(new Option(['dc' => 'lon-1', 'cpu' => 35]))
//!     ->addFilter(fn (Option $o) => $o->getState('cpu') < 50);
//!
//! echo $balancer->run()->getState('dc'), "\n"; // lon-1
//! ```
//!
//! PHP: ports `utopia-php/balancer`.
//!
//! | PHP | Rust |
//! |---|---|
//! | `Utopia\Balancer\Balancer` | [`Balancer`] (generic over the option type) |
//! | `Balancer::run()`, `getFilteredOptions()`, `getOptions()`, `getAlgo()` | [`Balancer::run`] (and [`Balancer::pick`] for the position), [`Balancer::filtered`], [`Balancer::options`], [`Balancer::algorithm`] |
//! | `Balancer::addOption()`, `addFilter()` | [`Balancer::add_option`], [`Balancer::add_filter`] |
//! | `Utopia\Balancer\Algorithm` | [`Algorithm`] (`run()` is [`Algorithm::pick`], returning a position) |
//! | `Algorithm\First`, `Last`, `Random`, `RoundRobin` | [`First`], [`Last`], [`Random`], [`RoundRobin`] |
//! | `RoundRobin::getIndex()`, `setIndex()` | [`RoundRobin::index`], [`RoundRobin::set_index`] |
//! | `Utopia\Balancer\Option` | [`Candidate`] |
//! | `Option::getState()`, `setState()`, `deleteState()`, `getStates()` | [`Candidate::get`] / [`Candidate::get_or`], [`Candidate::set`], [`Candidate::remove`], [`Candidate::states`] |
//! | `Utopia\Balancer\Group` | [`Group`] |
//! | `Group::add()`, `getOptions()`, `run()`, `setTelemetry()` | [`Group::add`], [`Group::options`], [`Group::run`], [`Group::set_telemetry`] |
//! | `\ValueError`, `\TypeError` | [`Error::Value`], [`Error::Type`] |
//!
//! PHP shares `Option` objects between balancers by reference, so state set
//! on one is seen by all. In Rust, share a `Arc<Mutex<Candidate>>` (or any
//! shared type) as the option type for the same effect, or clone values when
//! every balancer may hold its own copy.
//!
//! PHP's `Random` draws from the process-wide engine that `mt_srand()` seeds;
//! each [`Random`] here owns its generator, and [`Random::seeded`] gives the
//! sequence PHP draws after `mt_srand($seed)`.

#![warn(missing_docs)]

mod algorithm;
mod balancer;
mod candidate;
mod error;
mod group;

pub use algorithm::{Algorithm, First, Last, Random, RoundRobin};
pub use balancer::{Balancer, Filter};
pub use candidate::Candidate;
pub use error::Error;
pub use group::Group;

/// The getting started guide (`guide.md`), compiled and run with the doctests.
#[cfg(doctest)]
#[doc = include_str!("../guide.md")]
pub struct GettingStarted;
