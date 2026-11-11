//! Following `$ref` references inside one document.
//!
//! Only object references (parameters, responses, examples, security
//! schemes, path items) are followed while reading; schema references stay
//! [`SchemaKind::Reference`](crate::SchemaKind::Reference), because
//! recursive schema graphs are legal.
//!
//! PHP: `Utopia\OpenAPI\Reference`.
//!
//! | PHP | Rust |
//! |---|---|
//! | `Reference\Resolver` | [`Resolver`] |
//! | `Reference\LocalResolver` | [`LocalResolver`] |
//! | `Reference\Reference` | [`Reference`] |
//! | `Reference\ResolutionContext` | [`ResolutionContext`] |

use std::borrow::Cow;

use php_std::encoding::rawurldecode;
use php_std::types::KeyRef;
use php_std::{Array, Value};

use crate::Error;

/// A `$ref` value.
///
/// PHP: `Utopia\OpenAPI\Reference\Reference`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Reference {
    /// The reference as written (`#/components/parameters/id`).
    pub value: String,
}

impl Reference {
    /// A reference to `value`.
    pub fn new(value: impl Into<String>) -> Self {
        Reference { value: value.into() }
    }

    /// Whether the reference points into the same document (starts with `#`).
    ///
    /// PHP: `Reference::isLocal()`.
    ///
    /// ```
    /// use utopia_openapi::Reference;
    ///
    /// assert!(Reference::new("#/components/schemas/Pet").is_local());
    /// assert!(!Reference::new("schemas.json#/Pet").is_local());
    /// ```
    ///
    /// ```php
    /// use Utopia\OpenAPI\Reference\Reference;
    ///
    /// var_dump((new Reference('#/components/schemas/Pet'))->isLocal()); // bool(true)
    /// var_dump((new Reference('schemas.json#/Pet'))->isLocal());        // bool(false)
    /// ```
    pub fn is_local(&self) -> bool {
        self.value.starts_with('#')
    }
}

/// What a resolution already went through: the references followed so far
/// (to detect cycles) and the base URI of the document, for resolvers that
/// fetch other documents.
///
/// PHP: `Utopia\OpenAPI\Reference\ResolutionContext`.
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct ResolutionContext {
    /// References followed so far, in order.
    pub trail: Vec<String>,
    /// The URI relative references resolve against.
    pub base_uri: Option<String>,
}

/// Resolves a reference to the value it points to.
///
/// Implement it to resolve references [`LocalResolver`] refuses (other
/// documents, URLs).
///
/// PHP: `Utopia\OpenAPI\Reference\Resolver`.
pub trait Resolver {
    /// The value `reference` points to.
    ///
    /// PHP: `Resolver::resolve($reference, $context)`.
    fn resolve(&self, reference: &Reference, context: &ResolutionContext) -> Result<Value, Error>;
}

/// Resolves local references (`#/...` JSON Pointers) against one document;
/// any other reference is [`Error::ReferenceNotFound`].
///
/// PHP: `Utopia\OpenAPI\Reference\LocalResolver`.
#[derive(Debug, Clone, Copy)]
pub struct LocalResolver<'a> {
    document: &'a Array,
}

impl<'a> LocalResolver<'a> {
    /// A resolver over `document`.
    pub fn new(document: &'a Array) -> Self {
        LocalResolver { document }
    }

    /// Follows a chain of reference objects: while the value found is an
    /// object with a string `$ref`, follows that one too, failing with
    /// [`Error::CircularReference`] when a reference comes back.
    ///
    /// Schema references must not be followed with this: valid recursive
    /// schema graphs would never end.
    ///
    /// PHP: `LocalResolver::resolveObject($reference, $trail)`.
    ///
    /// ```
    /// use utopia_openapi::{LocalResolver, decode_document};
    ///
    /// let document = decode_document(br##"{"a": {"$ref": "#/b"}, "b": {"$ref": "#/a"}, "c": {"$ref": "#/d"}, "d": {"name": "id"}}"##)?;
    /// let resolver = LocalResolver::new(&document);
    /// assert!(resolver.resolve_object("#/c", Vec::new()).is_ok());
    /// let error = resolver.resolve_object("#/a", Vec::new()).unwrap_err();
    /// assert_eq!(error.to_string(), "Circular reference: #/a -> #/b -> #/a");
    /// # Ok::<(), utopia_openapi::Error>(())
    /// ```
    ///
    /// ```php
    /// use Utopia\OpenAPI\Reference\LocalResolver;
    ///
    /// $resolver = new LocalResolver(['a' => ['$ref' => '#/b'], 'b' => ['$ref' => '#/a'], 'c' => ['$ref' => '#/d'], 'd' => ['name' => 'id']]);
    /// var_dump($resolver->resolveObject('#/c')); // ['name' => 'id']
    /// try {
    ///     $resolver->resolveObject('#/a');
    /// } catch (\Utopia\OpenAPI\Exception\CircularReference $e) {
    ///     echo $e->getMessage(), "\n"; // Circular reference: #/a -> #/b -> #/a
    /// }
    /// ```
    pub fn resolve_object(&self, reference: &str, trail: Vec<String>) -> Result<Value, Error> {
        let mut context = ResolutionContext { trail, base_uri: None };
        let mut reference = Reference::new(reference);
        loop {
            let value = self.resolve(&reference, &context)?;
            let next = match &value {
                Value::Array(a) => match a.get_str("$ref") {
                    Some(Value::Str(s)) => Some(String::from_utf8_lossy(s).into_owned()),
                    _ => None,
                },
                _ => None,
            };
            let Some(next) = next else { return Ok(value) };
            context.trail.push(std::mem::replace(&mut reference.value, next));
        }
    }

    /// The value at a local JSON Pointer (`~1` is `/`, `~0` is `~`, and the
    /// pointer is percent-decoded first).
    fn pointer(&self, reference: &Reference, context: &ResolutionContext) -> Result<Value, Error> {
        let value = &reference.value;
        if !reference.is_local() {
            return Err(Error::ReferenceNotFound(format!("External reference is not configured: {value}")));
        }
        if context.trail.iter().any(|seen| seen == value) {
            let mut trail = context.trail.join(" -> ");
            trail.push_str(" -> ");
            trail.push_str(value);
            return Err(Error::CircularReference(format!("Circular reference: {trail}")));
        }
        let pointer = rawurldecode(&value.as_bytes()[1..]);
        if pointer.is_empty() {
            return Ok(Value::Array(self.document.clone()));
        }
        if pointer[0] != b'/' {
            return Err(Error::ReferenceNotFound(format!("Invalid local JSON Pointer: {value}")));
        }
        let mut current = self.document;
        let mut found: Option<&Value> = None;
        for encoded in pointer[1..].split(|&b| b == b'/') {
            let bad_escape =
                encoded.iter().enumerate().any(|(i, &b)| b == b'~' && !matches!(encoded.get(i + 1), Some(b'0' | b'1')));
            if bad_escape {
                return Err(Error::ReferenceNotFound(format!("Invalid JSON Pointer escape in reference: {value}")));
            }
            let token = unescape(encoded);
            if let Some(previous) = found {
                match previous {
                    Value::Array(a) => current = a,
                    _ => return Err(Error::ReferenceNotFound(format!("Reference not found: {value}"))),
                }
            }
            match current.get_ref(KeyRef::normalize(&token)) {
                Some(next) => found = Some(next),
                None => return Err(Error::ReferenceNotFound(format!("Reference not found: {value}"))),
            }
        }
        Ok(found.cloned().unwrap_or(Value::Null))
    }
}

/// `str_replace(['~1', '~0'], ['/', '~'], $token)`: two passes, in that order.
pub(crate) fn unescape(token: &[u8]) -> Cow<'_, [u8]> {
    fn replace(s: &[u8], from: &[u8; 2], to: u8) -> Vec<u8> {
        let mut out = Vec::with_capacity(s.len());
        let mut i = 0;
        while i < s.len() {
            if s[i..].starts_with(from) {
                out.push(to);
                i += 2;
            } else {
                out.push(s[i]);
                i += 1;
            }
        }
        out
    }
    if !token.contains(&b'~') {
        return Cow::Borrowed(token);
    }
    Cow::Owned(replace(&replace(token, b"~1", b'/'), b"~0", b'~'))
}

impl Resolver for LocalResolver<'_> {
    /// The value a local reference points to.
    ///
    /// PHP: `LocalResolver::resolve($reference, $context)`.
    fn resolve(&self, reference: &Reference, context: &ResolutionContext) -> Result<Value, Error> {
        self.pointer(reference, context)
    }
}
