//! What detection finds: the operating system, the client, the device and
//! the bot behind a user-agent string.
//!
//! Text is [`Text`]: a user-agent header may carry any byte, so names and
//! versions read from it (a device model, a native app's bundle identifier)
//! are the input's bytes, borrowed from it. Values from the detectors'
//! tables are static.
//!
//! PHP: strings are bytes there too; Rust borrows where PHP copies.

use std::borrow::Cow;

/// A text value: borrowed from the user-agent or from a static table, owned
/// when detection rewrites it (`17_4` becomes `17.4`).
pub type Text<'a> = Cow<'a, [u8]>;

/// `Some` static text.
pub(crate) fn text(value: &'static str) -> Option<Text<'static>> {
    Some(Cow::Borrowed(value.as_bytes()))
}

/// The operating system a user-agent string names; every field is `None`
/// when it names none.
///
/// PHP: `Utopia\UserAgent\OperatingSystem`.
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct OperatingSystem<'a> {
    /// Short code (`WIN`, `IOS`, `UBT`).
    pub code: Option<Text<'a>>,
    /// Display name (`Windows`, `iOS`, `Ubuntu`).
    pub name: Option<Text<'a>>,
    /// Version, when the string carries one (`7`, `17.4`).
    pub version: Option<Text<'a>>,
}

impl OperatingSystem<'_> {
    /// Whether the operating system has a name.
    ///
    /// PHP: `isKnown()`.
    pub fn is_known(&self) -> bool {
        self.name.is_some()
    }
}

/// The client a user-agent string names: a browser, an HTTP library or a
/// native app.
///
/// PHP: `Utopia\UserAgent\Client`.
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct Client<'a> {
    /// Kind of client: `browser`, `library` or `mobile app`.
    ///
    /// PHP: the `type` field.
    pub kind: Option<Text<'a>>,
    /// Short code of a browser (`CH`, `FF`); libraries and apps have none.
    pub code: Option<Text<'a>>,
    /// Display name (`Chrome`, `curl`, or a native app's bundle identifier).
    pub name: Option<Text<'a>>,
    /// Version of the client.
    pub version: Option<Text<'a>>,
    /// Rendering engine (`Blink`, `WebKit`, `Gecko`, `Trident`, `Presto`).
    pub engine: Option<Text<'a>>,
    /// Version of the rendering engine.
    pub engine_version: Option<Text<'a>>,
}

impl Client<'_> {
    /// Whether the client has a name.
    ///
    /// PHP: `isKnown()`.
    pub fn is_known(&self) -> bool {
        self.name.is_some()
    }

    /// Whether the client is a browser (its kind is `browser`).
    ///
    /// PHP: `isBrowser()`.
    pub fn is_browser(&self) -> bool {
        self.kind.as_deref() == Some(b"browser".as_slice())
    }
}

/// The device a user-agent string names.
///
/// PHP: `Utopia\UserAgent\Device`.
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct Device<'a> {
    /// Kind of device: `desktop`, `smartphone`, `tablet`, `tv`, `console`,
    /// `wearable` or `portable media player`.
    ///
    /// PHP: the `type` field.
    pub kind: Option<Text<'a>>,
    /// Manufacturer (`Apple`, `Samsung`).
    pub brand: Option<Text<'a>>,
    /// Model (`iPhone`, `iPad`, `Apple TV`).
    pub model: Option<Text<'a>>,
}

impl Device<'_> {
    /// Whether the device has a kind, a brand or a model.
    ///
    /// PHP: `isKnown()`.
    pub fn is_known(&self) -> bool {
        self.kind.is_some() || self.brand.is_some() || self.model.is_some()
    }
}

/// A bot a user-agent string names: a crawler, preview fetcher, monitor or
/// automation tool.
///
/// PHP: `Utopia\UserAgent\Bot`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Bot<'a> {
    /// Name of the bot (`Googlebot`, `GPTBot`).
    pub name: Text<'a>,
    /// `search crawler`, `ai crawler`, `social preview`, ... ([`Bot::CRAWLER`]
    /// for bots only the generic rule recognises).
    pub category: Text<'a>,
}

impl<'a> Bot<'a> {
    /// The default category, given to bots only the generic rule recognises.
    pub const CRAWLER: &'static str = "crawler";

    /// A bot of category [`Bot::CRAWLER`].
    ///
    /// PHP: `new Bot($name)`.
    pub fn new(name: impl Into<Text<'a>>) -> Self {
        Self { name: name.into(), category: Cow::Borrowed(Self::CRAWLER.as_bytes()) }
    }

    /// A bot of the given category.
    ///
    /// PHP: `new Bot($name, $category)`.
    pub fn with_category(name: impl Into<Text<'a>>, category: impl Into<Text<'a>>) -> Self {
        Self { name: name.into(), category: category.into() }
    }
}
