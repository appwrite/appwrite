/// Amazon S3 canned ACL grants applied to written objects.
///
/// Used by future S3-compatible adapters; local storage ignores this value.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum Acl {
    Private,
    PublicRead,
    PublicReadWrite,
    AuthenticatedRead,
}

impl Acl {
    pub const fn as_str(self) -> &'static str {
        match self {
            Self::Private => "private",
            Self::PublicRead => "public-read",
            Self::PublicReadWrite => "public-read-write",
            Self::AuthenticatedRead => "authenticated-read",
        }
    }
}
