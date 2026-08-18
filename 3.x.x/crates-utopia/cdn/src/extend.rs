//! PHP `Utopia\Cdn\Extend\CdnOption` plus an in-crate balancer subset.
//!
//! `utopia-php/balancer` is not ported. [`OptionBalancer`] covers `addOption`,
//! `addFilter`, `getFilteredOptions`, and `run()` with the `First` algorithm.

use std::collections::HashMap;
use std::sync::Arc;

use crate::{Adapter, CdnError, Configuration};

/// PHP `Utopia\Cdn\Extend\CdnOption`.
#[derive(Clone)]
pub struct CdnOption {
    state: HashMap<&'static str, StateValue>,
}

impl std::fmt::Debug for CdnOption {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("CdnOption")
            .field("provider", &self.get_provider().ok())
            .field("edge", &self.is_edge())
            .finish_non_exhaustive()
    }
}

#[derive(Clone)]
enum StateValue {
    Adapter(Arc<dyn Adapter>),
    Text(String),
    Flag(bool),
}

impl CdnOption {
    pub const ADAPTER: &'static str = "adapter";
    pub const PROVIDER: &'static str = "provider";
    pub const EDGE: &'static str = "edge";
    pub const PROVIDER_FASTLY: &'static str = "fastly";
    pub const PROVIDER_CLOUDFLARE: &'static str = "cloudflare";

    #[must_use]
    pub fn new(adapter: impl Adapter + 'static, provider: impl Into<String>, edge: bool) -> Self {
        let mut state = HashMap::new();
        state.insert(Self::ADAPTER, StateValue::Adapter(Arc::new(adapter)));
        state.insert(Self::PROVIDER, StateValue::Text(provider.into()));
        state.insert(Self::EDGE, StateValue::Flag(edge));
        Self { state }
    }

    pub fn get_adapter(&self) -> Result<Arc<dyn Adapter>, CdnError> {
        match self.state.get(Self::ADAPTER) {
            Some(StateValue::Adapter(adapter)) => Ok(Arc::clone(adapter)),
            _ => Err(Configuration(format!(
                "Option state \"{}\" must be a Utopia\\Cdn\\Cache\\Adapter.",
                Self::ADAPTER
            ))
            .into()),
        }
    }

    pub fn get_provider(&self) -> Result<&str, CdnError> {
        match self.state.get(Self::PROVIDER) {
            Some(StateValue::Text(provider)) => Ok(provider.as_str()),
            _ => Err(Configuration(format!(
                "Option state \"{}\" must be a string.",
                Self::PROVIDER
            ))
            .into()),
        }
    }

    /// PHP `isEdge()` - `getState(EDGE, false) === true`.
    #[must_use]
    pub fn is_edge(&self) -> bool {
        matches!(self.state.get(Self::EDGE), Some(StateValue::Flag(true)))
    }

    /// PHP `Option::setState` - tests overwrite typed state with a string.
    pub fn set_state(&mut self, key: &str, value: impl Into<String>) {
        let stored = StateValue::Text(value.into());
        if key == Self::ADAPTER {
            self.state.insert(Self::ADAPTER, stored);
        } else if key == Self::PROVIDER {
            self.state.insert(Self::PROVIDER, stored);
        } else if key == Self::EDGE {
            self.state.insert(Self::EDGE, stored);
        }
    }
}

/// PHP `Utopia\Balancer\Option` without a typed adapter.
#[derive(Clone, Debug, Default)]
pub struct UntypedOption;

/// Entry in an [`OptionBalancer`].
#[derive(Clone)]
pub enum OptionKind {
    Cdn(CdnOption),
    Untyped(UntypedOption),
}

impl std::fmt::Debug for OptionKind {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        match self {
            Self::Cdn(option) => f.debug_tuple("Cdn").field(option).finish(),
            Self::Untyped(option) => f.debug_tuple("Untyped").field(option).finish(),
        }
    }
}

impl From<CdnOption> for OptionKind {
    fn from(option: CdnOption) -> Self {
        Self::Cdn(option)
    }
}

impl From<UntypedOption> for OptionKind {
    fn from(option: UntypedOption) -> Self {
        Self::Untyped(option)
    }
}

type Filter = Arc<dyn Fn(&CdnOption) -> bool + Send + Sync>;

/// Minimal PHP `Utopia\Balancer\Balancer` + `Algorithm\First`.
#[derive(Clone, Default)]
pub struct OptionBalancer {
    options: Vec<OptionKind>,
    filters: Vec<Filter>,
}

impl std::fmt::Debug for OptionBalancer {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("OptionBalancer")
            .field("options", &self.options)
            .field("filters", &self.filters.len())
            .finish()
    }
}

impl OptionBalancer {
    #[must_use]
    pub fn new() -> Self {
        Self::default()
    }

    pub fn add_option(&mut self, option: impl Into<OptionKind>) -> &mut Self {
        self.options.push(option.into());
        self
    }

    pub fn add_cdn_option(&mut self, option: CdnOption) -> &mut Self {
        self.add_option(option)
    }

    pub fn add_filter<F>(&mut self, filter: F) -> &mut Self
    where
        F: Fn(&CdnOption) -> bool + Send + Sync + 'static,
    {
        self.filters.push(Arc::new(filter));
        self
    }

    /// PHP `getFilteredOptions()`, `CdnOption` entries only.
    #[must_use]
    pub fn get_filtered_options(&self) -> Vec<CdnOption> {
        self.filtered()
            .into_iter()
            .filter_map(|option| match option {
                OptionKind::Cdn(cdn) => Some(cdn),
                OptionKind::Untyped(_) => None,
            })
            .collect()
    }

    #[must_use]
    pub fn filtered(&self) -> Vec<OptionKind> {
        self.options
            .iter()
            .filter(|option| match option {
                OptionKind::Cdn(cdn) => self.filters.iter().all(|filter| filter(cdn)),
                OptionKind::Untyped(_) => true,
            })
            .cloned()
            .collect()
    }

    /// PHP `Balancer::run()` with the First algorithm.
    #[must_use]
    pub fn run(&self) -> Option<CdnOption> {
        self.get_filtered_options().into_iter().next()
    }
}
