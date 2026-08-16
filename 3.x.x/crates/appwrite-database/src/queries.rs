//! Query helpers for common Users API lookups. Rust port of the
//! `Query::equal`/`Query::search` call sites in
//! `src/Appwrite/Platform/Modules/Users/{Base,Http/Users/*}.php`.

use utopia_database::Query;

/// PHP `Query::search('search', $search)`, used by `Users\XList`,
/// `Targets\XList`, `Identities\XList`, and `Memberships\XList` to filter by
/// the fulltext `search` index.
#[must_use]
pub fn search(term: impl Into<String>) -> Query {
    Query::search("search", term.into())
}

/// PHP `Query::equal('userInternalId', [$sequence])`, used by
/// `Users\Delete` (cascading identity/target cleanup) and
/// `Memberships\XList`/`Targets\XList` to scope a query to one user's
/// internal (`$sequence`) ID.
#[must_use]
pub fn by_user_internal_id(sequence: i64) -> Query {
    Query::equal("userInternalId", vec![sequence.into()])
}

/// PHP `Query::equal('userId', [$userId])`, used by `Targets\XList` to scope
/// targets to one user's public ID.
#[must_use]
pub fn by_user_id(user_id: impl Into<String>) -> Query {
    let user_id: String = user_id.into();
    Query::equal("userId", vec![user_id.into()])
}

/// PHP `Query::equal('identifier', [$email])` / `Query::equal('identifier',
/// [$number])`, used by `Users\Base::createUser()` and `Email\Update`/
/// `Phone\Update` to look up an existing `targets` document for an
/// email/phone identifier.
#[must_use]
pub fn by_target_identifier(identifier: impl Into<String>) -> Query {
    let identifier: String = identifier.into();
    Query::equal("identifier", vec![identifier.into()])
}

/// PHP `Query::equal('providerEmail', [$email])`, used by
/// `Users\Base::createUser()` and `Email\Update` to look up an existing
/// `identities` document claiming an email via OAuth/OIDC.
#[must_use]
pub fn by_provider_email(email: impl Into<String>) -> Query {
    let email: String = email.into();
    Query::equal("providerEmail", vec![email.into()])
}
