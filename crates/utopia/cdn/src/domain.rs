//! The domain and path checks every purge and certificate call starts with.

use std::borrow::Cow;

use php_std::filter::validate_domain;

use crate::Error;

/// A domain in its canonical form: lower-cased (borrowed when it already
/// is), and a hostname without a scheme, port, path or trailing slash.
///
/// DNS labels are case-insensitive (RFC 4343), so case is folded rather
/// than rejected. Use the returned value: it is what providers and equality
/// checks expect.
///
/// Fails with [`Error::InvalidArgument`] for anything else.
///
/// ```
/// use utopia_cdn::domain;
///
/// assert_eq!(domain::validate("APP.Example.CoM")?, "app.example.com");
/// assert!(domain::validate("https://app.example.com").is_err());
/// # Ok::<(), utopia_cdn::Error>(())
/// ```
///
/// ```php
/// use Utopia\Cdn\Domain;
///
/// echo Domain::validate('APP.Example.CoM'), "\n"; // app.example.com
/// try {
///     Domain::validate('https://app.example.com');
/// } catch (\InvalidArgumentException $e) {
///     echo $e->getMessage(), "\n";
/// }
/// ```
///
/// PHP: `Domain::validate()`: `strtolower()` (ASCII only), then
/// `filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)`.
pub fn validate(domain: &str) -> Result<Cow<'_, str>, Error> {
    // The hostname rules ignore case, so the check runs before the folding.
    if domain.is_empty() || !validate_domain(domain.as_bytes(), true) {
        return Err(Error::InvalidArgument(
            "Domain must be a hostname without a scheme, port, path, or trailing slash.".into(),
        ));
    }
    Ok(if domain.bytes().any(|b| b.is_ascii_uppercase()) {
        Cow::Owned(domain.to_ascii_lowercase())
    } else {
        Cow::Borrowed(domain)
    })
}

/// Checks that every cache path begins with `/`.
///
/// Fails with [`Error::InvalidArgument`] otherwise.
///
/// ```
/// use utopia_cdn::domain;
///
/// assert!(domain::validate_paths(&["/index.html", "/assets/app.js"]).is_ok());
/// assert!(domain::validate_paths(&["index.html"]).is_err());
/// ```
///
/// ```php
/// use Utopia\Cdn\Domain;
///
/// echo json_encode(Domain::validatePaths(['/index.html', '/assets/app.js'])), "\n";
/// ```
///
/// PHP: `Domain::validatePaths()`, which returns the paths unchanged; an
/// entry that is not a string cannot be expressed here.
pub fn validate_paths<S: AsRef<str>>(paths: &[S]) -> Result<(), Error> {
    if paths.iter().all(|path| path.as_ref().starts_with('/')) {
        Ok(())
    } else {
        Err(Error::InvalidArgument("Every cache path must be a string beginning with \"/\".".into()))
    }
}
