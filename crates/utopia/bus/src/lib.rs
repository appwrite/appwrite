//! A synchronous, in-process event bus. Code that does something publishes a
//! typed [`Event`]; [`Listener`]s subscribed to that event type react to it,
//! each with the dependencies it declares, resolved by name through the
//! bus's resolver.
//!
//! [`Bus::dispatch`] calls every listener of the event's type in
//! subscription order. A listener that fails does not stop the others: its
//! failure is recorded on the current span (`listener.<name>.success`,
//! `listener.<name>.error.*`), next to `listener.<name>.event`.
//!
//! PHP: ports `utopia-php/bus`.
//!
//! | PHP | Rust |
//! |---|---|
//! | `Utopia\Bus\Event` (interface) | [`Event`] (trait) |
//! | `Utopia\Bus\Listener` (abstract class) | [`Listener`] |
//! | `Listener::getName()`, `getEvents()` | [`Listener::new`], [`Listener::on`] |
//! | `desc()`, `inject()`, `callback()` | [`Listener::desc`], [`Listener::inject`], [`Listener::callback`] |
//! | `getInjections()`, `getCallback()` | [`Listener::injections`], [`Listener::get_callback`] |
//! | `Utopia\Bus\Bus` | [`Bus`] |
//! | `setResolver()`, `subscribe()`, `dispatch()` | [`Bus::set_resolver`], [`Bus::subscribe`], [`Bus::dispatch`] |
//! | `\LogicException`, a listener's `\Throwable` | [`Error`], [`Failure`] |
//!
//! PHP: listeners are subclasses whose static `getName()` and `getEvents()`
//! name them and their event classes, and events are matched by class name.
//! Here a listener is a value built with its name and the event types it
//! handles, and events are matched by type.
//!
//! ```
//! use std::sync::{Arc, Mutex};
//! use utopia_bus::{Bus, Event, Listener, Resource};
//!
//! struct Deployed;
//! impl Event for Deployed {}
//!
//! let calls = Arc::new(Mutex::new(Vec::new()));
//! let seen = calls.clone();
//! let mut bus = Bus::new();
//! bus.set_resolver(|name| Ok(Arc::new(format!("{name}:resolved")) as Resource));
//! bus.subscribe(Listener::new("mailer").on::<Deployed>().inject("mailer").callback(move |_event, deps| {
//!     seen.lock().unwrap().push(deps[0].downcast_ref::<String>().cloned().unwrap_or_default());
//!     Ok(())
//! }));
//!
//! bus.dispatch(&Deployed)?;
//! assert_eq!(*calls.lock().unwrap(), ["mailer:resolved"]);
//! # Ok::<(), utopia_bus::Error>(())
//! ```
//!
//! ```php
//! use Utopia\Bus\Bus;
//! use Utopia\Bus\Event;
//! use Utopia\Bus\Listener;
//!
//! final class Deployed implements Event {}
//!
//! final class Mailer extends Listener
//! {
//!     public static function getName(): string { return 'mailer'; }
//!     public static function getEvents(): array { return [Deployed::class]; }
//!     public function __construct()
//!     {
//!         $this->inject('mailer')->callback(function (Event $event, string $mailer) {
//!             echo $mailer, "\n"; // mailer:resolved
//!         });
//!     }
//! }
//!
//! (new Bus())
//!     ->setResolver(fn (string $name) => $name . ':resolved')
//!     ->subscribe(new Mailer())
//!     ->dispatch(new Deployed());
//! ```

#![warn(missing_docs)]

use std::any::{Any, TypeId};
use std::collections::HashMap;
use std::panic::Location;
use std::sync::Arc;

use utopia_span::Span;

/// The getting started guide (`guide.md`), compiled and run with the doctests.
#[cfg(doctest)]
#[doc = include_str!("../guide.md")]
pub struct GettingStarted;

/// A resolved dependency a listener receives.
pub type Resource = Arc<dyn Any + Send + Sync>;

/// A listener's callback: the event and the listener's dependencies,
/// resolved in the order it injected them.
///
/// PHP: the callable given to `callback()`, called as
/// `$callback($event, ...$dependencies)`.
pub type Callback = Arc<dyn Fn(&dyn Event, &[Resource]) -> Result<(), Failure> + Send + Sync>;

/// Resolves a dependency by name.
///
/// PHP: the callable given to `setResolver()`.
pub type Resolver = Arc<dyn Fn(&str) -> Result<Resource, Failure> + Send + Sync>;

/// Something that happened, published on a [`Bus`]. Listeners subscribe to
/// event types.
///
/// PHP: `Utopia\Bus\Event`, a marker interface whose class identifies the
/// event.
///
/// ```
/// use utopia_bus::Event;
///
/// struct UserCreated { id: String }
/// impl Event for UserCreated {
///     fn name(&self) -> &str { "users.create" }
/// }
///
/// let event = UserCreated { id: "u1".into() };
/// let event: &dyn Event = &event;
/// assert_eq!(event.name(), "users.create");
/// assert_eq!(event.downcast_ref::<UserCreated>().map(|e| e.id.as_str()), Some("u1"));
/// ```
///
/// ```php
/// use Utopia\Bus\Event;
///
/// final class UserCreated implements Event { public function __construct(public string $id) {} }
///
/// $event = new UserCreated('u1');
/// echo $event::class, ' ', $event->id, "\n"; // UserCreated u1
/// ```
pub trait Event: Any + Send + Sync {
    /// The name recorded on the span for this event (`listener.<name>.event`):
    /// the type's name unless the event says otherwise.
    ///
    /// PHP: `$event::class`.
    fn name(&self) -> &str {
        std::any::type_name::<Self>()
    }
}

impl dyn Event {
    /// The event as a `T`, when it is one.
    ///
    /// PHP: `$event instanceof T`.
    pub fn downcast_ref<T: Event>(&self) -> Option<&T> {
        (self as &dyn Any).downcast_ref::<T>()
    }

    /// Whether the event is a `T`.
    pub fn is<T: Event>(&self) -> bool {
        (self as &dyn Any).is::<T>()
    }
}

/// A listener's failure, as recorded on the span: a message, a code and
/// where it was raised.
///
/// PHP: the `\Throwable` a callback throws (`getCode()`, `getMessage()`,
/// `getLine()`, `getFile()`, `getTraceAsString()`).
///
/// ```
/// use utopia_bus::Failure;
///
/// let failure = Failure::new("Mailer is down").with_code(503);
/// assert_eq!(failure.message(), "Mailer is down");
/// assert_eq!(failure.code(), 503);
/// assert!(failure.file().ends_with(".rs"));
/// ```
///
/// ```php
/// $e = new \RuntimeException('Mailer is down', 503);
/// echo $e->getMessage(), ' ', $e->getCode(), "\n"; // Mailer is down 503
/// ```
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Failure {
    message: String,
    code: i64,
    file: &'static str,
    line: u32,
}

impl Failure {
    /// A failure with `message` and code 0, raised where this is called.
    #[track_caller]
    pub fn new(message: impl Into<String>) -> Self {
        let at = Location::caller();
        Self { message: message.into(), code: 0, file: at.file(), line: at.line() }
    }

    /// Sets the code.
    pub fn with_code(mut self, code: i64) -> Self {
        self.code = code;
        self
    }

    /// The message.
    pub fn message(&self) -> &str {
        &self.message
    }

    /// The code.
    pub fn code(&self) -> i64 {
        self.code
    }

    /// The source file it was raised in.
    pub fn file(&self) -> &str {
        self.file
    }

    /// The line it was raised on.
    pub fn line(&self) -> u32 {
        self.line
    }
}

impl std::fmt::Display for Failure {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.write_str(&self.message)
    }
}

impl<E: std::error::Error> From<E> for Failure {
    /// The error's message, raised where it is converted (the `?`).
    #[track_caller]
    fn from(e: E) -> Self {
        Failure::new(e.to_string())
    }
}

/// Why a dispatch failed: listeners' own failures never fail it.
///
/// PHP: the exceptions `Bus::dispatch()` lets through.
#[derive(Debug, Clone, PartialEq, Eq, thiserror::Error)]
pub enum Error {
    /// The bus has no resolver, or a listener has no callback.
    ///
    /// PHP: `\LogicException`.
    #[error("{0}")]
    Logic(String),
    /// The resolver failed to resolve a listener's dependency.
    ///
    /// PHP: the exception the resolver throws (here `\Exception`).
    #[error("{0}")]
    Resolver(Failure),
}

impl Error {
    /// The name of the PHP exception class this error matches.
    pub fn php_class(&self) -> &'static str {
        match self {
            Error::Logic(_) => "LogicException",
            Error::Resolver(_) => "Exception",
        }
    }
}

/// Reacts to events of the types it is subscribed to, with the dependencies
/// it injects.
///
/// PHP: a subclass of `Utopia\Bus\Listener`.
///
/// ```
/// use utopia_bus::{Event, Listener};
///
/// struct Deployed;
/// impl Event for Deployed {}
///
/// let listener = Listener::new("clock").desc("Records deploy times").on::<Deployed>().inject("clock");
/// assert_eq!(listener.name(), "clock");
/// assert_eq!(listener.injections(), ["clock"]);
/// assert!(listener.get_callback().is_err());
/// ```
///
/// ```php
/// use Utopia\Bus\Event;
/// use Utopia\Bus\Listener;
///
/// final class Deployed implements Event {}
///
/// final class Clock extends Listener
/// {
///     public static function getName(): string { return 'clock'; }
///     public static function getEvents(): array { return [Deployed::class]; }
///     public function __construct() { $this->desc('Records deploy times')->inject('clock'); }
/// }
///
/// $listener = new Clock();
/// echo implode(',', $listener->getInjections()), "\n"; // clock
/// try {
///     $listener->getCallback();
/// } catch (\LogicException $e) {
///     echo $e->getMessage(), "\n"; // Clock must set a callback via $this->callback()
/// }
/// ```
#[derive(Clone)]
pub struct Listener {
    name: String,
    events: Vec<TypeId>,
    desc: Option<String>,
    injections: Vec<String>,
    callback: Option<Callback>,
}

impl std::fmt::Debug for Listener {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Listener")
            .field("name", &self.name)
            .field("desc", &self.desc)
            .field("injections", &self.injections)
            .field("callback", &self.callback.is_some())
            .finish()
    }
}

impl Listener {
    /// A listener named `name` (used in span attributes), handling no event
    /// type yet.
    ///
    /// PHP: `getName()`.
    pub fn new(name: impl Into<String>) -> Self {
        Self { name: name.into(), events: Vec::new(), desc: None, injections: Vec::new(), callback: None }
    }

    /// Subscribes the listener to events of type `E` too. A type listed twice
    /// is handled twice per event.
    ///
    /// PHP: an entry of `getEvents()`.
    pub fn on<E: Event>(mut self) -> Self {
        self.events.push(TypeId::of::<E>());
        self
    }

    /// Describes the listener.
    ///
    /// PHP: `desc($desc)`.
    pub fn desc(mut self, desc: impl Into<String>) -> Self {
        self.desc = Some(desc.into());
        self
    }

    /// Declares a dependency, resolved by name on every dispatch and passed
    /// to the callback in declaration order.
    ///
    /// PHP: `inject($injection)`.
    pub fn inject(mut self, name: impl Into<String>) -> Self {
        self.injections.push(name.into());
        self
    }

    /// Sets the callback.
    ///
    /// PHP: `callback($callback)`.
    pub fn callback<F>(mut self, f: F) -> Self
    where
        F: Fn(&dyn Event, &[Resource]) -> Result<(), Failure> + Send + Sync + 'static,
    {
        self.callback = Some(Arc::new(f));
        self
    }

    /// The listener's name.
    ///
    /// PHP: `getName()`.
    pub fn name(&self) -> &str {
        &self.name
    }

    /// The description, if set.
    pub fn description(&self) -> Option<&str> {
        self.desc.as_deref()
    }

    /// The event types the listener handles.
    ///
    /// PHP: `getEvents()`.
    pub fn events(&self) -> &[TypeId] {
        &self.events
    }

    /// The names of the declared dependencies, in order.
    ///
    /// PHP: `getInjections()`.
    pub fn injections(&self) -> &[String] {
        &self.injections
    }

    /// The callback; [`Error::Logic`] (`<name> must set a callback via
    /// callback()`) when none is set.
    ///
    /// PHP: `getCallback()`, whose message names the listener's class.
    pub fn get_callback(&self) -> Result<&Callback, Error> {
        self.callback.as_ref().ok_or_else(|| Error::Logic(format!("{} must set a callback via callback()", self.name)))
    }
}

/// Delivers events to the listeners subscribed to their type.
///
/// PHP: `Utopia\Bus\Bus`.
///
/// ```
/// use utopia_bus::{Bus, Error, Event};
///
/// struct Deployed;
/// impl Event for Deployed {}
///
/// let error = Bus::new().dispatch(&Deployed).unwrap_err();
/// assert!(matches!(error, Error::Logic(_)));
/// assert_eq!(error.to_string(), "Bus resolver must be set via setResolver() before dispatching events");
/// ```
///
/// ```php
/// use Utopia\Bus\Bus;
/// use Utopia\Bus\Event;
///
/// final class Deployed implements Event {}
///
/// try {
///     (new Bus())->dispatch(new Deployed());
/// } catch (\LogicException $e) {
///     echo $e->getMessage(), "\n"; // Bus resolver must be set via setResolver() before dispatching events
/// }
/// ```
#[derive(Clone, Default)]
pub struct Bus {
    listeners: HashMap<TypeId, Vec<Arc<Listener>>>,
    resolver: Option<Resolver>,
}

impl std::fmt::Debug for Bus {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Bus")
            .field("listeners", &self.listeners.values().map(Vec::len).sum::<usize>())
            .field("resolver", &self.resolver.is_some())
            .finish()
    }
}

impl Bus {
    /// A bus without listeners or resolver.
    pub fn new() -> Self {
        Self::default()
    }

    /// Sets how dependencies are resolved by name.
    ///
    /// PHP: `setResolver($resolver)`.
    pub fn set_resolver<F>(&mut self, resolver: F) -> &mut Self
    where
        F: Fn(&str) -> Result<Resource, Failure> + Send + Sync + 'static,
    {
        self.resolver = Some(Arc::new(resolver));
        self
    }

    /// Subscribes a listener to each event type it handles.
    ///
    /// PHP: `subscribe($listener)`.
    pub fn subscribe(&mut self, listener: impl Into<Arc<Listener>>) -> &mut Self {
        let listener = listener.into();
        for event in &listener.events {
            self.listeners.entry(*event).or_default().push(listener.clone());
        }
        self
    }

    /// Calls every listener subscribed to the event's type, in subscription
    /// order, with its dependencies resolved.
    ///
    /// Records `listener.<name>.event` on the current span before each call,
    /// then `listener.<name>.success`, and on failure
    /// `listener.<name>.error.code`, `.message`, `.line`, `.file` and
    /// `.trace`; a failing listener (or one without a callback) does not
    /// stop the others. Fails with [`Error::Logic`] without a resolver, and
    /// with [`Error::Resolver`] when a dependency cannot be resolved.
    ///
    /// PHP: `dispatch($event)`.
    pub fn dispatch(&self, event: &dyn Event) -> Result<(), Error> {
        let Some(resolver) = &self.resolver else {
            return Err(Error::Logic(
                "Bus resolver must be set via setResolver() before dispatching events".to_owned(),
            ));
        };
        let Some(listeners) = self.listeners.get(&(event as &dyn Any).type_id()) else {
            return Ok(());
        };
        for listener in listeners {
            let deps = listener
                .injections
                .iter()
                .map(|name| resolver(name))
                .collect::<Result<Vec<_>, _>>()
                .map_err(Error::Resolver)?;
            let key = |suffix: &str| format!("listener.{}.{suffix}", listener.name);
            Span::add(&key("event"), event.name());
            let outcome = match listener.get_callback() {
                Ok(callback) => callback(event, &deps),
                Err(e) => Err(Failure::new(e.to_string())),
            };
            match outcome {
                Ok(()) => Span::add(&key("success"), true),
                Err(failure) => {
                    Span::add(&key("success"), false);
                    Span::add(&key("error.code"), failure.code);
                    Span::add(&key("error.message"), failure.message.as_str());
                    Span::add(&key("error.line"), i64::from(failure.line));
                    Span::add(&key("error.file"), failure.file);
                    Span::add(&key("error.trace"), std::backtrace::Backtrace::capture().to_string().as_str());
                }
            }
        }
        Ok(())
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::sync::Mutex;

    struct Deployed;
    impl Event for Deployed {}
    struct Cancelled;
    impl Event for Cancelled {}

    #[test]
    fn skips_other_events() {
        let calls = Arc::new(Mutex::new(Vec::new()));
        let mut bus = Bus::new();
        bus.set_resolver(|n| Ok(Arc::new(n.to_owned()) as Resource));
        for (name, deployed) in [("clock", true), ("cancelled", false)] {
            let calls = calls.clone();
            let listener = Listener::new(name).inject(name).callback(move |_, deps| {
                calls.lock().unwrap().push(deps[0].downcast_ref::<String>().cloned().unwrap());
                Ok(())
            });
            bus.subscribe(if deployed { listener.on::<Deployed>() } else { listener.on::<Cancelled>() });
        }
        bus.dispatch(&Deployed).unwrap();
        assert_eq!(*calls.lock().unwrap(), ["clock"]);
    }
}
