//! The parsed OpenID Connect `prompt` request parameter.

use super::Prompt;
use crate::Error;

/// The parsed OpenID Connect `prompt` parameter: distinct values in
/// first-seen order, where `none` stands alone.
///
/// PHP: `Utopia\Auth\OAuth2\Prompts`.
///
/// ```
/// use utopia_auth::oauth2::{Prompt, Prompts};
///
/// let prompts = Prompts::from_string(b"login consent login")?;
/// assert_eq!(prompts.to_vec(), ["login", "consent"]);
/// assert!(prompts.contains(Prompt::Consent));
/// assert!(Prompts::from_string(b"none login").is_err());
/// # Ok::<(), utopia_auth::Error>(())
/// ```
///
/// ```php
/// use Utopia\Auth\Enums\Prompt;
/// use Utopia\Auth\OAuth2\Prompts;
///
/// $prompts = Prompts::fromString('login consent login');
/// echo implode(',', $prompts->toArray()), "\n";        // login,consent
/// var_dump($prompts->contains(Prompt::Consent));       // bool(true)
/// ```
#[derive(Debug, Clone, PartialEq, Eq, Default)]
pub struct Prompts(Vec<Prompt>);

impl Prompts {
    /// Parses space-delimited prompt values; an empty string requests none.
    ///
    /// Fails with [`Error::InvalidPrompt`] for an unknown value, or when
    /// `none` is combined with another.
    ///
    /// PHP: `Prompts::fromString($prompt)`.
    pub fn from_string(prompt: &[u8]) -> Result<Self, Error> {
        let mut prompts = Vec::new();
        for value in prompt.split(|b| *b == b' ').filter(|v| !v.is_empty()) {
            let Some(p) = Prompt::from_name(value) else {
                return Err(Error::InvalidPrompt([b"Invalid prompt value '", value, b"'."].concat()));
            };
            if !prompts.contains(&p) {
                prompts.push(p);
            }
        }
        if prompts.contains(&Prompt::None) && prompts.len() > 1 {
            return Err(Error::InvalidPrompt("prompt=none cannot be combined with other prompt values.".into()));
        }
        Ok(Self(prompts))
    }

    /// Whether `prompt` was requested.
    ///
    /// PHP: `contains($prompt)`.
    pub fn contains(&self, prompt: Prompt) -> bool {
        self.0.contains(&prompt)
    }

    /// The requested values, in first-seen order.
    ///
    /// PHP: `toArray()`.
    pub fn to_vec(&self) -> Vec<&'static str> {
        self.0.iter().map(|p| p.as_str()).collect()
    }

    /// The requested values, space-delimited, as a request carries them.
    ///
    /// PHP: `toString()`.
    pub fn to_string_value(&self) -> String {
        self.to_vec().join(" ")
    }
}
