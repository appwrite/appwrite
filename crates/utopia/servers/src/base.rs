//! What every server keeps: its mode, the hooks it runs around its work, its
//! container, and how it resolves a hook's params.

use std::sync::Arc;

use serde_json::{Map, Value};
use utopia_di::Container;
use utopia_validators::Validator;

use crate::Error;
use crate::hook::{Check, Default, Hook, Param, Resource};

/// The development mode.
///
/// PHP: `Base::MODE_TYPE_DEVELOPMENT`.
pub const MODE_TYPE_DEVELOPMENT: &str = "development";
/// The staging mode.
///
/// PHP: `Base::MODE_TYPE_STAGE`.
pub const MODE_TYPE_STAGE: &str = "stage";
/// The production mode.
///
/// PHP: `Base::MODE_TYPE_PRODUCTION`.
pub const MODE_TYPE_PRODUCTION: &str = "production";

/// The state a Utopia-style server keeps: its mode, its init, shutdown and
/// error hooks (in every group, `*`), its start and end hooks, and its
/// container. [`Base::prepare`] resolves and validates a hook's params.
///
/// PHP: `Utopia\Servers\Base`, an abstract class whose hooks and mode are
/// static (shared by every server of the process). A `Base` value keeps its
/// own; a server embeds one.
///
/// ```
/// use utopia_servers::{Base, MODE_TYPE_PRODUCTION};
///
/// let mut base = Base::new();
/// base.set_mode(MODE_TYPE_PRODUCTION);
/// assert!(base.is_production());
/// base.init().desc("before every action");
/// assert_eq!(base.init_hooks()[0].get_groups(), ["*"]);
/// ```
///
/// ```php
/// use Utopia\Servers\Base;
///
/// Base::setMode(Base::MODE_TYPE_PRODUCTION);
/// var_dump(Base::isProduction());                      // bool(true)
/// echo implode(',', Base::init()->getGroups()), "\n"; // *
/// ```
#[derive(Debug, std::default::Default)]
pub struct Base {
    mode: String,
    errors: Vec<Hook>,
    init: Vec<Hook>,
    shutdown: Vec<Hook>,
    start: Vec<Hook>,
    end: Vec<Hook>,
    container: Option<Arc<Container>>,
}

impl Base {
    /// No hooks, no mode (empty) and no container.
    pub fn new() -> Self {
        Self::default()
    }

    fn push<'a>(list: &'a mut Vec<Hook>, groups: &[&str]) -> &'a mut Hook {
        let mut hook = Hook::new();
        hook.groups(groups);
        list.push(hook);
        let last = list.len() - 1;
        &mut list[last]
    }

    /// Adds a hook run before the work, in every group (`*`).
    ///
    /// PHP: `Base::init()`.
    pub fn init(&mut self) -> &mut Hook {
        Self::push(&mut self.init, &["*"])
    }

    /// Adds a hook run after the work, in every group (`*`).
    ///
    /// PHP: `Base::shutdown()`.
    pub fn shutdown(&mut self) -> &mut Hook {
        Self::push(&mut self.shutdown, &["*"])
    }

    /// Adds a hook run when the work fails, in every group (`*`).
    ///
    /// PHP: `Base::error()`.
    pub fn error(&mut self) -> &mut Hook {
        Self::push(&mut self.errors, &["*"])
    }

    /// Adds a hook run when the server starts (in no group).
    ///
    /// PHP: `Base::onStart()`.
    pub fn on_start(&mut self) -> &mut Hook {
        Self::push(&mut self.start, &[])
    }

    /// Adds a hook run when the server ends (in no group).
    ///
    /// PHP: `Base::onEnd()`.
    pub fn on_end(&mut self) -> &mut Hook {
        Self::push(&mut self.end, &[])
    }

    /// The init hooks, in order.
    pub fn init_hooks(&self) -> &[Hook] {
        &self.init
    }

    /// The shutdown hooks, in order.
    pub fn shutdown_hooks(&self) -> &[Hook] {
        &self.shutdown
    }

    /// The error hooks, in order.
    pub fn error_hooks(&self) -> &[Hook] {
        &self.errors
    }

    /// The start hooks, in order.
    pub fn start_hooks(&self) -> &[Hook] {
        &self.start
    }

    /// The end hooks, in order.
    pub fn end_hooks(&self) -> &[Hook] {
        &self.end
    }

    /// The mode: any string, empty until set.
    ///
    /// PHP: `Base::getMode()`.
    pub fn mode(&self) -> &str {
        &self.mode
    }

    /// Sets the mode.
    ///
    /// PHP: `Base::setMode($value)`.
    pub fn set_mode(&mut self, mode: &str) {
        mode.clone_into(&mut self.mode);
    }

    /// Whether the mode is [`MODE_TYPE_PRODUCTION`].
    ///
    /// PHP: `Base::isProduction()`.
    pub fn is_production(&self) -> bool {
        self.mode == MODE_TYPE_PRODUCTION
    }

    /// Whether the mode is [`MODE_TYPE_DEVELOPMENT`].
    ///
    /// PHP: `Base::isDevelopment()`.
    pub fn is_development(&self) -> bool {
        self.mode == MODE_TYPE_DEVELOPMENT
    }

    /// Whether the mode is [`MODE_TYPE_STAGE`].
    ///
    /// PHP: `Base::isStage()`.
    pub fn is_stage(&self) -> bool {
        self.mode == MODE_TYPE_STAGE
    }

    /// The container, once set.
    ///
    /// PHP: `getContainer()` (an `Error` before `setContainer()`).
    pub fn container(&self) -> Option<&Arc<Container>> {
        self.container.as_ref()
    }

    /// Sets the container.
    ///
    /// PHP: `setContainer($container)`.
    pub fn set_container(&mut self, container: Arc<Container>) -> &mut Self {
        self.container = Some(container);
        self
    }

    /// Forgets the mode and every hook.
    ///
    /// PHP: `Base::reset()`.
    pub fn reset(&mut self) {
        self.mode.clear();
        self.errors.clear();
        self.init.clear();
        self.shutdown.clear();
        self.start.clear();
        self.end.clear();
    }

    /// Resolves the params of `hook` into a child container of `context`.
    ///
    /// Each param takes its value from `values` (path values, which win)
    /// or `request`, under its key or else its first alias present, or else
    /// from its default (a factory is called with its injections). Unless it
    /// skips validation, a param that is neither in `values` nor `request`
    /// must be optional ([`Error::Server`] `Param "<key>" is not optional.`,
    /// code 400), and one that is must pass its validator
    /// (``Invalid `<key>` param: <description>``, code 400) unless it is
    /// optional and `null`. The value is stored on the hook
    /// ([`Hook::param_value`]) and in the returned container under the key.
    ///
    /// PHP: `prepare($context, $hook, $values, $requestParams)`.
    ///
    /// ```
    /// use std::sync::Arc;
    /// use serde_json::{json, Map};
    /// use utopia_di::Container;
    /// use utopia_servers::{Base, Hook, Param};
    /// use utopia_validators::Text;
    ///
    /// let mut hook = Hook::new();
    /// hook.param("name", Param::new(json!(""), Text::new(5), ""));
    ///
    /// let request: Map<_, _> = [("name".to_owned(), json!("Ada"))].into_iter().collect();
    /// let scope = Base::new().prepare(&Arc::new(Container::new()), &mut hook, &Map::new(), &request)?;
    /// assert_eq!(*scope.get::<serde_json::Value>("name")?, json!("Ada"));
    ///
    /// let request: Map<_, _> = [("name".to_owned(), json!("Ada Lovelace"))].into_iter().collect();
    /// let error = Base::new().prepare(&Arc::new(Container::new()), &mut hook, &Map::new(), &request).unwrap_err();
    /// assert_eq!(error.to_string(), "Invalid `name` param: Value must be a valid string and at least 1 chars and no longer than 5 chars");
    /// # Ok::<(), Box<dyn std::error::Error>>(())
    /// ```
    ///
    /// ```php
    /// // Base::prepare() is protected: servers call it for each hook they run.
    /// use Utopia\DI\Container;
    /// use Utopia\Servers\Base;
    /// use Utopia\Servers\Hook;
    /// use Utopia\Validator\Text;
    ///
    /// $server = new class () extends Base {
    ///     public function start() {}
    ///     public function run(Hook $hook, array $request): Container { return $this->prepare(new Container(), $hook, [], $request); }
    /// };
    /// $hook = (new Hook())->param('name', '', new Text(5));
    /// echo $server->run($hook, ['name' => 'Ada'])->get('name'), "\n"; // Ada
    /// ```
    pub fn prepare(
        &self,
        context: &Arc<Container>,
        hook: &mut Hook,
        values: &Map<String, Value>,
        request: &Map<String, Value>,
    ) -> Result<Container, Error> {
        let scope = Container::with_parent(context.clone());
        let keys: Vec<String> = hook.params().keys().cloned().collect();
        for key in keys {
            let param = &hook.params()[&key];
            let pick = |map: &Map<String, Value>| -> Option<Value> {
                if let Some(v) = map.get(&key) {
                    return Some(v.clone());
                }
                param.aliases.iter().find_map(|a| map.get(a)).cloned()
            };
            let from_request = pick(request);
            let from_values = pick(values);
            let exists = from_request.is_some() || from_values.is_some();
            let arg = match from_request {
                Some(v) => v,
                None => match &param.default {
                    Default::Value(v) => v.clone(),
                    Default::Factory(f) => f(&resolve(&scope, &param.injections)?),
                },
            };
            let value = from_values.unwrap_or(arg);

            if !param.skip_validation {
                if !exists && !param.optional {
                    return Err(Error::server(format!("Param \"{key}\" is not optional."), 400));
                }
                if exists {
                    self.validate(&key, param, &value, &scope)?;
                }
            }

            hook.set_param_value(&key, value.clone())?;
            scope.set(&key, &[], move |_| Ok(value.clone()));
        }
        Ok(scope)
    }

    /// Validates `value` for the param `key`: nothing to check when the param
    /// is optional and the value `null`; otherwise its validator (built with
    /// its injections from `context`, when a factory) must accept it, or
    /// [`Error::Server`] (``Invalid `<key>` param: <description>``, code
    /// 400).
    ///
    /// PHP: `validate($key, $param, $value, $context)`.
    pub fn validate(&self, key: &str, param: &Param, value: &Value, context: &Container) -> Result<(), Error> {
        if param.optional && value.is_null() {
            return Ok(());
        }
        let validator: Arc<dyn Validator> = match &param.validator {
            Check::Validator(v) => v.clone(),
            Check::Factory(f) => f(&resolve(context, &param.injections)?),
        };
        if !validator.is_valid(value) {
            return Err(Error::server(format!("Invalid `{key}` param: {}", validator.description()), 400));
        }
        Ok(())
    }
}

/// Resolves `names` from `container`, in order.
fn resolve(container: &Container, names: &[String]) -> Result<Vec<Resource>, Error> {
    names.iter().map(|n| container.get_any(n).map_err(Error::from)).collect()
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;
    use utopia_validators::Text;

    #[test]
    fn missing_required() {
        let mut hook = Hook::new();
        hook.param("x", Param::new(json!(""), Text::new(10), ""));
        let e = Base::new().prepare(&Arc::new(Container::new()), &mut hook, &Map::new(), &Map::new()).unwrap_err();
        assert_eq!(e.to_string(), "Param \"x\" is not optional.");
        assert_eq!(e.code(), 400);
    }

    #[test]
    fn values_win_over_request() {
        let mut hook = Hook::new();
        hook.param("x", Param::new(json!(""), Text::new(10), "").aliases(&["y"]));
        let values: Map<_, _> = [("y".to_owned(), json!("path"))].into_iter().collect();
        let request: Map<_, _> = [("x".to_owned(), json!("query"))].into_iter().collect();
        Base::new().prepare(&Arc::new(Container::new()), &mut hook, &values, &request).unwrap();
        assert_eq!(hook.param_value("x").unwrap(), &json!("path"));
    }
}
