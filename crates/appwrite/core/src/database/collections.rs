//! Queryable attributes of system collections (`app/config/collections`).
//!
//! Only attributes that matter for query validation and SQL typing are
//! listed; `Queries` validators further restrict them to an allow-list.

use utopia_database::schema::{Attribute, Collection};

pub const USERS: Collection = Collection {
    id: "users",
    attributes: &[
        Attribute::string("name", 256),
        Attribute::string("email", 320),
        Attribute::string("phone", 16),
        Attribute::boolean("status"),
        Attribute::string("labels", 128).array(),
        Attribute::string("password", 16384).encrypted(),
        Attribute::datetime("passwordUpdate"),
        Attribute::datetime("registration"),
        Attribute::boolean("emailVerification"),
        Attribute::boolean("phoneVerification"),
        Attribute::boolean("passwordPwned"),
        Attribute::boolean("impersonator"),
        Attribute::datetime("accessedAt"),
        Attribute::string("search", 16384),
    ],
    searchable: true,
};

pub const TARGETS: Collection = Collection {
    id: "targets",
    attributes: &[
        Attribute::string("userId", 255),
        Attribute::string("userInternalId", 255),
        Attribute::string("providerId", 255),
        Attribute::string("identifier", 255),
        Attribute::string("providerType", 255),
    ],
    searchable: false,
};

pub const MEMBERSHIPS: Collection = Collection {
    id: "memberships",
    attributes: &[
        Attribute::string("userId", 255),
        Attribute::string("userInternalId", 255),
        Attribute::string("teamId", 255),
        Attribute::datetime("invited"),
        Attribute::datetime("joined"),
        Attribute::boolean("confirm"),
        Attribute::string("roles", 128).array(),
        Attribute::string("search", 16384),
    ],
    searchable: true,
};

pub const IDENTITIES: Collection = Collection {
    id: "identities",
    attributes: &[
        Attribute::string("userId", 255),
        Attribute::string("provider", 128),
        Attribute::string("providerUid", 2048),
        Attribute::string("providerEmail", 320),
        Attribute::datetime("providerAccessTokenExpiry"),
    ],
    searchable: false,
};

pub const AUTHENTICATORS: Collection = Collection {
    id: "authenticators",
    attributes: &[
        Attribute::string("userInternalId", 255),
        Attribute::string("type", 255),
        Attribute::boolean("verified"),
        Attribute::string("name", 128),
        Attribute::datetime("accessedAt"),
    ],
    searchable: false,
};

/// Allow-lists of the Appwrite `Queries\*` validators.
pub mod allowed {
    pub const USERS: &[&str] = &[
        "name",
        "email",
        "phone",
        "status",
        "passwordUpdate",
        "registration",
        "emailVerification",
        "phoneVerification",
        "passwordPwned",
        "labels",
        "impersonator",
        "accessedAt",
    ];
    pub const TARGETS: &[&str] = &["userId", "providerId", "identifier", "providerType"];
    pub const MEMBERSHIPS: &[&str] = &["userId", "teamId", "invited", "joined", "confirm", "roles"];
    pub const IDENTITIES: &[&str] =
        &["userId", "provider", "providerUid", "providerEmail", "providerAccessTokenExpiry"];
    pub const PASSKEYS: &[&str] = &["name", "accessedAt"];
}
