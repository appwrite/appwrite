//! What detection finds: the operating system, the client, the device and
//! the bot behind a user-agent string.
//!
//! Text is [`Text`]: PHP strings are bytes and a user-agent header may carry
//! any byte, so names and versions read from it (a device model, a native
//! app's bundle identifier) are the input's bytes, borrowed from it where
//! PHP would copy them. Values from the detectors' tables are static.

use std::borrow::Cow;

/// A text value: borrowed from the user-agent or from a static table, owned
/// when detection rewrites it (`17_4` becomes `17.4`).
pub type Text<'a> = Cow<'a, [u8]>;

/// `Some` static text.
pub(crate) fn text(value: &'static str) -> Option<Text<'static>> {
    Some(Cow::Borrowed(value.as_bytes()))
}

/// `Utopia\UserAgent\OperatingSystem`.
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct OperatingSystem<'a> {
    /// Short code (`WIN`, `IOS`, `UBT`).
    pub code: Option<Text<'a>>,
    pub name: Option<Text<'a>>,
    pub version: Option<Text<'a>>,
}

impl OperatingSystem<'_> {
    /// `isKnown()`: the operating system has a name.
    pub fn is_known(&self) -> bool {
        self.name.is_some()
    }
}

/// `Utopia\UserAgent\Client`: a browser, an HTTP library or a native app.
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct Client<'a> {
    /// `type`: `browser`, `library` or `mobile app`.
    pub kind: Option<Text<'a>>,
    /// Short code of a browser (`CH`, `FF`); libraries and apps have none.
    pub code: Option<Text<'a>>,
    pub name: Option<Text<'a>>,
    pub version: Option<Text<'a>>,
    /// Rendering engine (`Blink`, `WebKit`, `Gecko`, `Trident`, `Presto`).
    pub engine: Option<Text<'a>>,
    pub engine_version: Option<Text<'a>>,
}

impl Client<'_> {
    /// `isKnown()`: the client has a name.
    pub fn is_known(&self) -> bool {
        self.name.is_some()
    }

    /// `isBrowser()`: the client's type is `browser`.
    pub fn is_browser(&self) -> bool {
        self.kind.as_deref() == Some(b"browser".as_slice())
    }
}

/// `Utopia\UserAgent\Device`.
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct Device<'a> {
    /// `type`: `desktop`, `smartphone`, `tablet`, `tv`, `console`,
    /// `wearable` or `portable media player`.
    pub kind: Option<Text<'a>>,
    pub brand: Option<Text<'a>>,
    pub model: Option<Text<'a>>,
}

impl Device<'_> {
    /// `isKnown()`: the device has a type, a brand or a model.
    pub fn is_known(&self) -> bool {
        self.kind.is_some() || self.brand.is_some() || self.model.is_some()
    }
}

/// `Utopia\UserAgent\Bot`: a crawler, preview fetcher, monitor or
/// automation tool.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Bot<'a> {
    pub name: Text<'a>,
    /// `search crawler`, `ai crawler`, `social preview`, ... ([`Bot::CRAWLER`]
    /// for bots only the generic rule recognises).
    pub category: Text<'a>,
}

impl<'a> Bot<'a> {
    /// The category of `new Bot($name)`.
    pub const CRAWLER: &'static str = "crawler";

    /// `new Bot($name)`: a bot of category [`Bot::CRAWLER`].
    pub fn new(name: impl Into<Text<'a>>) -> Self {
        Self { name: name.into(), category: Cow::Borrowed(Self::CRAWLER.as_bytes()) }
    }

    /// `new Bot($name, $category)`.
    pub fn with_category(name: impl Into<Text<'a>>, category: impl Into<Text<'a>>) -> Self {
        Self { name: name.into(), category: category.into() }
    }
}
