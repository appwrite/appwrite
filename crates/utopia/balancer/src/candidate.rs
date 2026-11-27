//! [`Candidate`]: one option a balancer can choose, described by its state.

use php_std::types::KeyRef;
use php_std::{Array, Value};

/// One option to balance between, described by a bag of named values (its
/// state): a hostname, whether it is online, its load.
///
/// Keys follow PHP array rules: a decimal key such as `"0"` is stored as the
/// integer `0`, so [`Candidate::states`] is the array PHP would hold.
///
/// PHP: `Utopia\Balancer\Option`.
///
/// ```
/// use php_std::Value;
/// use utopia_balancer::Candidate;
///
/// let mut worker = Candidate::default();
/// worker.set("hostname", Value::from("worker-1")).set("cpu", Value::from(35));
///
/// assert_eq!(worker.get("cpu"), Some(&Value::Int(35)));
/// assert_eq!(worker.get_or("memory", &Value::Int(0)), &Value::Int(0));
/// assert_eq!(worker.states().len(), 2);
/// ```
///
/// ```php
/// use Utopia\Balancer\Option;
///
/// $worker = new Option([]);
/// $worker->setState('hostname', 'worker-1')->setState('cpu', 35);
///
/// var_dump($worker->getState('cpu'));         // int(35)
/// var_dump($worker->getState('memory', 0));   // int(0)
/// var_dump(\count($worker->getStates()));     // int(2)
/// ```
#[derive(Debug, Clone, Default, PartialEq)]
pub struct Candidate {
    state: Array,
}

impl Candidate {
    /// A candidate with this initial state.
    ///
    /// PHP: `new Option($state)`.
    pub fn new(state: Array) -> Self {
        Candidate { state }
    }

    /// The value of `key`, or `None` when it is missing or `null`.
    ///
    /// PHP: `getState($key)`, which returns `null` for both.
    pub fn get(&self, key: &str) -> Option<&Value> {
        self.state.get_ref(KeyRef::normalize(key.as_bytes())).filter(|v| !matches!(v, Value::Null))
    }

    /// The value of `key`, or `default` when it is missing or `null`.
    ///
    /// PHP: `getState($key, $default)` (`$state[$key] ?? $default`).
    pub fn get_or<'a>(&'a self, key: &str, default: &'a Value) -> &'a Value {
        self.get(key).unwrap_or(default)
    }

    /// Sets `key` to `value`, replacing any previous value in place.
    ///
    /// PHP: `setState($key, $value)`.
    pub fn set(&mut self, key: &str, value: Value) -> &mut Self {
        self.state.set(KeyRef::normalize(key.as_bytes()).to_owned(), value);
        self
    }

    /// Removes `key`, if present.
    ///
    /// PHP: `deleteState($key)`.
    pub fn remove(&mut self, key: &str) -> &mut Self {
        self.state.remove(&KeyRef::normalize(key.as_bytes()).to_owned());
        self
    }

    /// Every value, in the order the keys were first set.
    ///
    /// PHP: `getStates()`.
    pub fn states(&self) -> &Array {
        &self.state
    }
}

impl<const N: usize> From<[(&str, Value); N]> for Candidate {
    /// A candidate from `(key, value)` pairs, in order.
    fn from(pairs: [(&str, Value); N]) -> Self {
        let mut candidate = Candidate::default();
        for (key, value) in pairs {
            candidate.set(key, value);
        }
        candidate
    }
}

impl From<Array> for Candidate {
    /// A candidate with this state.
    fn from(state: Array) -> Self {
        Candidate::new(state)
    }
}
