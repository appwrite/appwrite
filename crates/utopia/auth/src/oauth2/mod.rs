//! Request values an OAuth2 / OpenID Connect authorization server parses and
//! checks: prompts, redirect URIs, resource indicators, authorization
//! details, pushed authorization requests and client metadata documents.
//!
//! PHP: `Utopia\Auth\OAuth2\*`.

mod authorization_details;
mod client_id_metadata_document;
mod client_identifier_url;
mod par;
mod prompts;
mod redirect_uris;
mod resource_indicators;

pub use authorization_details::AuthorizationDetails;
pub use client_id_metadata_document::ClientIdMetadataDocument;
pub use client_identifier_url::ClientIdentifierUrl;
pub use par::Par;
pub use prompts::Prompts;
pub use redirect_uris::RedirectUris;
pub use resource_indicators::{ResourceIndicators, Resources};

/// A value of the OpenID Connect `prompt` request parameter.
///
/// PHP: `Utopia\Auth\Enums\Prompt`.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum Prompt {
    /// `none`: no interaction.
    None,
    /// `login`: authenticate again.
    Login,
    /// `consent`: ask for consent again.
    Consent,
    /// `select_account`: let the user pick an account.
    SelectAccount,
}

impl Prompt {
    /// Every prompt, in declaration order.
    pub const ALL: [Prompt; 4] = [Prompt::None, Prompt::Login, Prompt::Consent, Prompt::SelectAccount];

    /// The value as it appears in a request (`"login"`, `"select_account"`, ...).
    ///
    /// PHP: the enum's backing value.
    pub fn as_str(self) -> &'static str {
        match self {
            Prompt::None => "none",
            Prompt::Login => "login",
            Prompt::Consent => "consent",
            Prompt::SelectAccount => "select_account",
        }
    }

    /// The prompt with the value `name`, or `None` for any other value.
    ///
    /// PHP: `Prompt::tryFrom($value)`.
    pub fn from_name(name: &[u8]) -> Option<Prompt> {
        Self::ALL.into_iter().find(|p| p.as_str().as_bytes() == name)
    }
}

/// A common field of an `authorization_details` entry (RFC 9396).
///
/// PHP: `Utopia\Auth\Enums\AuthorizationDetail`.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum AuthorizationDetail {
    /// `type`: the kind of authorization the entry describes.
    Type,
    /// `locations`: where the resource is.
    Locations,
    /// `actions`: what may be done with the resource.
    Actions,
    /// `datatypes`: the kinds of data requested.
    Datatypes,
    /// `identifier`: a specific resource.
    Identifier,
    /// `privileges`: the levels of access requested.
    Privileges,
}

impl AuthorizationDetail {
    /// Every field, in declaration order.
    pub const ALL: [AuthorizationDetail; 6] = [
        AuthorizationDetail::Type,
        AuthorizationDetail::Locations,
        AuthorizationDetail::Actions,
        AuthorizationDetail::Datatypes,
        AuthorizationDetail::Identifier,
        AuthorizationDetail::Privileges,
    ];

    /// The field's name in an entry (`"type"`, `"actions"`, ...).
    ///
    /// PHP: the enum's backing value.
    pub fn as_str(self) -> &'static str {
        match self {
            AuthorizationDetail::Type => "type",
            AuthorizationDetail::Locations => "locations",
            AuthorizationDetail::Actions => "actions",
            AuthorizationDetail::Datatypes => "datatypes",
            AuthorizationDetail::Identifier => "identifier",
            AuthorizationDetail::Privileges => "privileges",
        }
    }

    /// The field named `name`, or `None` for any other field.
    ///
    /// PHP: `AuthorizationDetail::tryFrom($value)`.
    pub fn from_name(name: &str) -> Option<AuthorizationDetail> {
        Self::ALL.into_iter().find(|d| d.as_str() == name)
    }
}

/// `parse_url()` components the OAuth2 values look at.
pub(crate) struct UrlParts {
    pub scheme: Option<Vec<u8>>,
    pub host: Option<Vec<u8>>,
    pub port: Option<u16>,
    pub user: Option<Vec<u8>>,
    pub pass: Option<Vec<u8>>,
    pub path: Option<Vec<u8>>,
    pub query: Option<Vec<u8>>,
    pub fragment: Option<Vec<u8>>,
}

/// `parse_url($url)`: `None` where PHP returns `false`.
pub(crate) fn parse_url(url: &[u8]) -> Option<UrlParts> {
    let u = php_std::url::parse_url(url)?;
    Some(UrlParts {
        scheme: u.scheme().map(|c| c.into_owned()),
        host: u.host().map(|c| c.into_owned()),
        port: u.port(),
        user: u.user().map(|c| c.into_owned()),
        pass: u.pass().map(|c| c.into_owned()),
        path: u.path().map(|c| c.into_owned()),
        query: u.query().map(|c| c.into_owned()),
        fragment: u.fragment().map(|c| c.into_owned()),
    })
}
