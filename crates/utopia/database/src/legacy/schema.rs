//! Collection schemas used for query validation and SQL typing.

/// Attribute types (`Database::VAR_*`).
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum AttributeType {
    String,
    Integer,
    Float,
    Boolean,
    Datetime,
    /// Internal sequence (`$sequence`, column `_id`).
    Id,
}

/// One attribute of a collection.
#[derive(Debug, Clone, Copy)]
pub struct Attribute {
    pub id: &'static str,
    pub kind: AttributeType,
    pub size: u32,
    pub signed: bool,
    pub array: bool,
    pub encrypted: bool,
}

impl Attribute {
    pub const fn string(id: &'static str, size: u32) -> Self {
        Self { id, kind: AttributeType::String, size, signed: true, array: false, encrypted: false }
    }
    pub const fn integer(id: &'static str, size: u32) -> Self {
        Self { id, kind: AttributeType::Integer, size, signed: true, array: false, encrypted: false }
    }
    pub const fn float(id: &'static str) -> Self {
        Self { id, kind: AttributeType::Float, size: 0, signed: true, array: false, encrypted: false }
    }
    pub const fn boolean(id: &'static str) -> Self {
        Self { id, kind: AttributeType::Boolean, size: 0, signed: true, array: false, encrypted: false }
    }
    pub const fn datetime(id: &'static str) -> Self {
        Self { id, kind: AttributeType::Datetime, size: 0, signed: false, array: false, encrypted: false }
    }
    pub const fn array(mut self) -> Self {
        self.array = true;
        self
    }
    pub const fn encrypted(mut self) -> Self {
        self.encrypted = true;
        self
    }

    /// Physical column for an attribute id.
    pub fn column(id: &str) -> &str {
        match id {
            "$id" => "_uid",
            "$sequence" => "_id",
            "$createdAt" => "_createdAt",
            "$updatedAt" => "_updatedAt",
            "$permissions" => "_permissions",
            "$tenant" => "_tenant",
            other => other,
        }
    }
}

/// Internal attributes every collection exposes to queries.
pub const INTERNAL: [Attribute; 4] = [
    Attribute::string("$id", 255),
    Attribute::datetime("$createdAt"),
    Attribute::datetime("$updatedAt"),
    Attribute { id: "$sequence", kind: AttributeType::Integer, size: 0, signed: true, array: false, encrypted: false },
];

/// A collection: its id and the attributes queries may reference.
#[derive(Debug, Clone, Copy)]
pub struct Collection {
    pub id: &'static str,
    pub attributes: &'static [Attribute],
    /// Whether the collection has a fulltext index on `search`.
    pub searchable: bool,
}

impl Collection {
    /// Finds an attribute (including internal ones).
    pub fn attribute(&self, id: &str) -> Option<&Attribute> {
        self.attributes.iter().find(|a| a.id == id).or_else(|| INTERNAL.iter().find(|a| a.id == id))
    }
}
