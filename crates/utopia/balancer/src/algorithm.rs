//! [`Algorithm`]: how a balancer chooses one option, and the four it ships
//! with: [`First`], [`Last`], [`Random`] and [`RoundRobin`].

use php_std::random::Mt19937;

use crate::Error;

/// Chooses one of the options that passed a balancer's filters.
///
/// An algorithm sees the options in the order they were added and returns
/// the position of the one it picks, or `None` to pick nothing. It may keep
/// state between calls, as [`RoundRobin`] does. Implement it to add an
/// algorithm (least connections, weighted, by latency).
///
/// PHP: `Utopia\Balancer\Algorithm`; `pick()` is `run()`, returning the
/// position instead of the option.
///
/// ```
/// use utopia_balancer::{Algorithm, Candidate, Error};
///
/// /// The option with the lowest `cpu`.
/// struct Idlest;
///
/// impl Algorithm<Candidate> for Idlest {
///     fn name(&self) -> &str {
///         "Idlest"
///     }
///
///     fn pick(&mut self, options: &[&Candidate]) -> Result<Option<usize>, Error> {
///         let cpu = |c: &Candidate| c.get("cpu").map_or(i64::MAX, |v| v.to_double() as i64);
///         Ok((0..options.len()).min_by_key(|&i| cpu(options[i])))
///     }
/// }
///
/// let busy = Candidate::from([("cpu", 80.into())]);
/// let idle = Candidate::from([("cpu", 5.into())]);
/// assert_eq!(Idlest.pick(&[&busy, &idle])?, Some(1));
/// # Ok::<(), Error>(())
/// ```
///
/// ```php
/// use Utopia\Balancer\Algorithm;
/// use Utopia\Balancer\Option;
///
/// final class Idlest extends Algorithm
/// {
///     public function getName(): string
///     {
///         return 'Idlest';
///     }
///
///     public function run(array $options): ?Option
///     {
///         usort($options, fn (Option $a, Option $b) => $a->getState('cpu') <=> $b->getState('cpu'));
///
///         return $options[0] ?? null;
///     }
/// }
///
/// $busy = new Option(['cpu' => 80]);
/// $idle = new Option(['cpu' => 5]);
/// var_dump((new Idlest())->run([$busy, $idle]) === $idle); // bool(true)
/// ```
pub trait Algorithm<T: ?Sized> {
    /// The algorithm's name, such as `RoundRobin`.
    ///
    /// PHP: `getName()`.
    fn name(&self) -> &str;

    /// The position in `options` of the option to use, or `None` for none.
    ///
    /// PHP: `run($options)`.
    fn pick(&mut self, options: &[&T]) -> Result<Option<usize>, Error>;
}

impl<T: ?Sized, A: Algorithm<T> + ?Sized> Algorithm<T> for Box<A> {
    /// The boxed algorithm's name.
    fn name(&self) -> &str {
        (**self).name()
    }

    /// Picks with the boxed algorithm.
    fn pick(&mut self, options: &[&T]) -> Result<Option<usize>, Error> {
        (**self).pick(options)
    }
}

/// Always the first option.
///
/// PHP: `Utopia\Balancer\Algorithm\First`.
#[derive(Debug, Clone, Copy, Default, PartialEq, Eq)]
pub struct First;

impl<T: ?Sized> Algorithm<T> for First {
    /// `First`.
    fn name(&self) -> &str {
        "First"
    }

    /// The first position, or `None` without options.
    fn pick(&mut self, options: &[&T]) -> Result<Option<usize>, Error> {
        Ok(if options.is_empty() { None } else { Some(0) })
    }
}

/// Always the last option.
///
/// PHP: `Utopia\Balancer\Algorithm\Last`.
#[derive(Debug, Clone, Copy, Default, PartialEq, Eq)]
pub struct Last;

impl<T: ?Sized> Algorithm<T> for Last {
    /// `Last`.
    fn name(&self) -> &str {
        "Last"
    }

    /// The last position, or `None` without options.
    fn pick(&mut self, options: &[&T]) -> Result<Option<usize>, Error> {
        Ok(options.len().checked_sub(1))
    }
}

/// A uniformly random option, from PHP's Mersenne Twister.
///
/// [`Random::seeded`] replays exactly what PHP picks after `mt_srand($seed)`,
/// so a choice can be reproduced in tests and compared with PHP.
///
/// PHP: `Utopia\Balancer\Algorithm\Random` (`array_rand()` on the default
/// engine).
///
/// ```
/// use utopia_balancer::{Algorithm, Random};
///
/// let options = ["fra-1", "fra-2", "lon-1"];
/// let refs: Vec<&&str> = options.iter().collect();
///
/// let mut random = Random::seeded(7);
/// let picks: Vec<_> = (0..3).map(|_| random.pick(&refs).unwrap().unwrap()).collect();
/// assert_eq!(picks, [0, 1, 1]);
/// ```
///
/// ```php
/// use Utopia\Balancer\Algorithm\Random;
/// use Utopia\Balancer\Option;
///
/// $options = [new Option(['dc' => 'fra-1']), new Option(['dc' => 'fra-2']), new Option(['dc' => 'lon-1'])];
///
/// mt_srand(7);
/// $random = new Random();
/// foreach ([1, 2, 3] as $_) {
///     echo $random->run($options)->getState('dc'), "\n"; // fra-1, fra-2, fra-2
/// }
/// ```
#[derive(Debug, Clone)]
pub struct Random {
    rng: Mt19937,
}

impl Random {
    /// Picks seeded from the operating system.
    ///
    /// PHP: `new Random()`, on the default engine PHP seeds at startup.
    pub fn new() -> Self {
        Random { rng: Mt19937::from_entropy() }
    }

    /// Picks that PHP makes after `mt_srand($seed)`.
    pub fn seeded(seed: i64) -> Self {
        Random { rng: Mt19937::new(seed) }
    }
}

impl Default for Random {
    /// [`Random::new`].
    fn default() -> Self {
        Random::new()
    }
}

impl<T: ?Sized> Algorithm<T> for Random {
    /// `Random`.
    fn name(&self) -> &str {
        "Random"
    }

    /// A random position. Without options this is an [`Error::Value`].
    ///
    /// PHP: `array_rand()` throws a `ValueError` for an empty array.
    fn pick(&mut self, options: &[&T]) -> Result<Option<usize>, Error> {
        self.rng.array_rand(options.len()).map(Some).map_err(|e| Error::Value(e.message))
    }
}

/// Each option in turn: every pick moves to the next position and wraps to
/// the first after the last.
///
/// The index is the position picked last. It starts where it is created
/// (`-1` starts at the first option) and can be moved with
/// [`RoundRobin::set_index`], to share a rotation across processes.
///
/// PHP: `Utopia\Balancer\Algorithm\RoundRobin`.
///
/// ```
/// use utopia_balancer::{Algorithm, RoundRobin};
///
/// let options = ["fra-1", "fra-2", "lon-1"];
/// let refs: Vec<&&str> = options.iter().collect();
///
/// let mut rr = RoundRobin::new(-1);
/// let picks: Vec<_> = (0..4).map(|_| rr.pick(&refs).unwrap().unwrap()).collect();
/// assert_eq!(picks, [0, 1, 2, 0]);
/// assert_eq!(rr.index(), 0);
/// ```
///
/// ```php
/// use Utopia\Balancer\Algorithm\RoundRobin;
/// use Utopia\Balancer\Option;
///
/// $options = [new Option(['dc' => 'fra-1']), new Option(['dc' => 'fra-2']), new Option(['dc' => 'lon-1'])];
///
/// $rr = new RoundRobin(-1);
/// foreach ([1, 2, 3, 4] as $_) {
///     echo $rr->run($options)->getState('dc'), "\n"; // fra-1, fra-2, lon-1, fra-1
/// }
/// echo $rr->getIndex(), "\n"; // 0
/// ```
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct RoundRobin {
    index: i64,
}

impl RoundRobin {
    /// A rotation whose last pick was `last_index`.
    ///
    /// PHP: `new RoundRobin($lastIndex)`.
    pub fn new(last_index: i64) -> Self {
        RoundRobin { index: last_index }
    }

    /// The position picked last.
    ///
    /// PHP: `getIndex()`.
    pub fn index(&self) -> i64 {
        self.index
    }

    /// Moves the rotation, as if `last_index` had been picked last.
    ///
    /// PHP: `setIndex($lastIndex)`.
    pub fn set_index(&mut self, last_index: i64) -> &mut Self {
        self.index = last_index;
        self
    }
}

impl<T: ?Sized> Algorithm<T> for RoundRobin {
    /// `RoundRobin`.
    fn name(&self) -> &str {
        "RoundRobin"
    }

    /// The position after the last one, or the first when that is past the
    /// end (or before the start). Without options the index resets to `0`
    /// and nothing is picked.
    ///
    /// PHP: an index at `PHP_INT_MAX` cannot be incremented (a `TypeError`
    /// for the typed property); that is [`Error::Type`] here, with the index
    /// left as it was.
    fn pick(&mut self, options: &[&T]) -> Result<Option<usize>, Error> {
        let Some(next) = self.index.checked_add(1) else {
            return Err(Error::Type(
                "Cannot increment property Utopia\\Balancer\\Algorithm\\RoundRobin::$index of type int past its maximal value"
                    .to_owned(),
            ));
        };
        if let Ok(position) = usize::try_from(next)
            && position < options.len()
        {
            self.index = next;
            return Ok(Some(position));
        }
        self.index = 0;
        Ok(if options.is_empty() { None } else { Some(0) })
    }
}
