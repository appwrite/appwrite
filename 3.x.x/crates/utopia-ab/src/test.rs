//! Server-side A/B test runner (PHP `Utopia\AB\Test`).

use crate::error::AbError;
use rand::Rng;
use std::collections::HashMap;
use std::fmt;
use std::sync::{Mutex, MutexGuard, OnceLock, PoisonError};

static RESULTS: OnceLock<Mutex<HashMap<String, String>>> = OnceLock::new();

fn results_lock() -> MutexGuard<'static, HashMap<String, String>> {
    RESULTS
        .get_or_init(|| Mutex::new(HashMap::new()))
        .lock()
        .unwrap_or_else(PoisonError::into_inner)
}

/// Callable variation, invoked only by [`Test::run`].
pub type VariationCallback = Box<dyn Fn() -> String + Send + Sync>;

/// Value of a named variation.
///
/// PHP accepts `mixed` values and executes callables in `run()`. Tests use
/// strings and closures that return strings.
pub enum VariationValue {
    /// Immediate string result (PHP non-callable value).
    String(String),
    /// Closure executed only when the test is run.
    Callback(VariationCallback),
}

impl VariationValue {
    /// Wrap a callback that produces the variation string when [`Test::run`] fires.
    pub fn callback<F>(f: F) -> Self
    where
        F: Fn() -> String + Send + Sync + 'static,
    {
        Self::Callback(Box::new(f))
    }

    fn resolve(&self) -> String {
        match self {
            Self::String(value) => value.clone(),
            Self::Callback(func) => func(),
        }
    }
}

impl fmt::Debug for VariationValue {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        match self {
            Self::String(value) => f.debug_tuple("String").field(value).finish(),
            Self::Callback(_) => f.write_str("Callback(..)"),
        }
    }
}

impl From<String> for VariationValue {
    fn from(value: String) -> Self {
        Self::String(value)
    }
}

impl From<&str> for VariationValue {
    fn from(value: &str) -> Self {
        Self::String(value.to_owned())
    }
}

impl From<&String> for VariationValue {
    fn from(value: &String) -> Self {
        Self::String(value.clone())
    }
}

struct Variation {
    name: String,
    value: VariationValue,
    /// PHP `null` when omitted. `Some(0.0)` is empty like PHP `empty(0)`.
    probability: Option<f64>,
}

impl fmt::Debug for Variation {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.debug_struct("Variation")
            .field("name", &self.name)
            .field("value", &self.value)
            .field("probability", &self.probability)
            .finish()
    }
}

/// PHP `empty($value)` for a stored probability (`null` or numeric `0`).
fn php_empty_probability(value: Option<f64>) -> bool {
    match value {
        None => true,
        Some(number) => number == 0.0,
    }
}

/// Server-side A/B test with weighted variations.
///
/// Process-wide results are recorded under the test name, matching PHP
/// `Utopia\AB\Test::$results`.
pub struct Test {
    name: String,
    variations: Vec<Variation>,
}

impl fmt::Debug for Test {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.debug_struct("Test")
            .field("name", &self.name)
            .field("variations", &self.variations)
            .finish()
    }
}

impl Test {
    /// Create a test with the given name (PHP `__construct(string $name)`).
    pub fn new(name: impl Into<String>) -> Self {
        Self {
            name: name.into(),
            variations: Vec::new(),
        }
    }

    /// Snapshot of every test result recorded in this process.
    ///
    /// PHP: `Test::results(): array`.
    pub fn results() -> HashMap<String, String> {
        results_lock().clone()
    }

    /// Clear process-wide results. Rust helper for tests (not in PHP).
    pub fn reset_results() {
        results_lock().clear();
    }

    /// Add or replace a variation (PHP `variation($name, $value, $probability = null)`).
    ///
    /// Returns `&mut Self` for fluent chaining like PHP `$this`.
    pub fn variation(
        &mut self,
        name: impl Into<String>,
        value: impl Into<VariationValue>,
        probability: Option<i32>,
    ) -> &mut Self {
        let name = name.into();
        let value = value.into();
        let probability = probability.map(f64::from);
        if let Some(existing) = self
            .variations
            .iter_mut()
            .find(|variation| variation.name == name)
        {
            existing.value = value;
            existing.probability = probability;
        } else {
            self.variations.push(Variation {
                name,
                value,
                probability,
            });
        }
        self
    }

    /// Pick a variation, execute a callback value if needed, and record the result.
    ///
    /// Callables run only here, not when [`Self::variation`] is called.
    pub fn run(&mut self) -> Result<String, AbError> {
        let chosen = self.chance()?;
        let resolved = self
            .variations
            .iter()
            .find(|variation| variation.name == chosen)
            .ok_or(AbError::NoVariation)?
            .value
            .resolve();
        results_lock().insert(self.name.clone(), resolved.clone());
        Ok(resolved)
    }

    /// Weighted random pick (PHP `chance()`).
    ///
    /// Missing / PHP-empty probabilities (`null` or `0`) share the remainder to
    /// 100 equally. Throws when the sum of stored probabilities is greater than
    /// 100. Selection uses PHP `rand(0, (int) array_sum($probabilities) * 10)`
    /// inclusive, so a 100% variation always wins and a 0% variation never wins
    /// when the remaining mass is 100%.
    fn chance(&mut self) -> Result<String, AbError> {
        let mut sum = 0.0;
        let mut empty = 0usize;
        for variation in &self.variations {
            sum += variation.probability.unwrap_or(0.0);
            if php_empty_probability(variation.probability) {
                empty += 1;
            }
        }

        if sum > 100.0 {
            return Err(AbError::ProbabilitiesExceed100);
        }

        if sum < 100.0 && empty > 0 {
            let fill = (100.0 - sum) / empty as f64;
            for variation in &mut self.variations {
                if php_empty_probability(variation.probability) {
                    variation.probability = Some(fill);
                }
            }
        }

        let total: f64 = self
            .variations
            .iter()
            .map(|variation| variation.probability.unwrap_or(0.0))
            .sum();
        // PHP: `(int) array_sum($this->probabilities) * 10` - cast before `*`.
        let max = u64::try_from((total as i64).saturating_mul(10)).unwrap_or(0);
        let number = rand::thread_rng().gen_range(0..=max);
        let mut starter = 0.0;
        for variation in &self.variations {
            starter += variation.probability.unwrap_or(0.0) * 10.0;
            if (number as f64) <= starter {
                return Ok(variation.name.clone());
            }
        }

        Err(AbError::NoVariation)
    }
}
