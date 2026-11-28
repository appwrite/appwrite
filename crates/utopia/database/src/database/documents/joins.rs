//! What reads share: the query validators, selections, and the joins'
//! collections and authorization.
//!
//! PHP: `getDocumentsValidator()`, `getQueriesValidator()`,
//! `validateDocumentsQueries()`, `resolveJoinedCollections()`,
//! `resolveOneToManyJoins()`, `prepareFilterJoinQueries()`,
//! `assertJoinCount()`, `authorizeJoins()`, `withJoinAuthorization()`,
//! `withJoinAttributes()`, `withJoinIndexed()`, `joinedCollectionsByAlias()`,
//! `outerJoinIdSelections()`, `validateSelections()` of `Trait\Documents`.

use std::sync::Arc;

use utopia_query::schema::IndexType;
use utopia_query::{Method, Object, Value as QValue};

use super::super::Database;
use crate::attribute::Attribute;
use crate::capability::Capability;
use crate::collection::Collection;
use crate::document::{self, Document};
use crate::error::{Error, Result};
use crate::permission::PermissionType;
use crate::query::Query;
use crate::relationship::{RelationshipSide, RelationshipType};
use crate::validator::authorization::Input;
use crate::validator::queries::documents::Documents as DocumentsValidator;
use crate::validator::queries::narrow::Narrow;
use crate::validator::query::join::Join as JoinValidator;
use crate::value::{Array, ArrayKey, Str, Value};

/// Collections by the id (or alias) a join names them with, in order.
pub(crate) type Joined = Vec<(String, Collection)>;

/// Looks a collection up in a [`Joined`] list.
pub(crate) fn joined<'a>(collections: &'a Joined, key: &str) -> Option<&'a Collection> {
    collections.iter().find(|(k, _)| k == key).map(|(_, c)| c)
}

/// The validator of a query list (`getQueriesValidator()`'s `Base`).
pub(crate) enum QueriesValidator {
    /// A narrow list's validator.
    Narrow(Narrow),
    /// The collection's documents validator.
    Documents(Arc<DocumentsValidator>),
}

impl QueriesValidator {
    /// Whether the list is valid (`isValid()`).
    pub(crate) fn check(&self, queries: &[Query]) -> bool {
        match self {
            QueriesValidator::Narrow(v) => v.check(queries),
            QueriesValidator::Documents(v) => v.check(queries),
        }
    }

    /// `getDescription()`.
    pub(crate) fn description(&self) -> String {
        match self {
            QueriesValidator::Narrow(v) => v.description(),
            QueriesValidator::Documents(v) => v.description(),
        }
    }

    /// `setJoinedCollections()`.
    pub(crate) fn set_joined_collections(&self, collections: &[Document]) -> Result<()> {
        match self {
            QueriesValidator::Narrow(v) => v.set_joined_collections(collections),
            QueriesValidator::Documents(v) => v.set_joined_collections(collections),
        }
    }
}

/// The index types a join can look its rows up by (`LOOKUP_INDEX_TYPES`).
const LOOKUP_INDEX_TYPES: [IndexType; 3] = [IndexType::Key, IndexType::Index, IndexType::Unique];

impl Database {
    /// The documents validator of a collection, kept per collection, tenant,
    /// limit and definition when there are no joins
    /// (`getDocumentsValidator()`).
    ///
    /// A mirror uses its source's, so it reuses the source's cache.
    pub(crate) fn documents_validator(&self, collection: &Collection, joins: bool) -> Result<Arc<DocumentsValidator>> {
        if let Some(parts) = self.inner.mirror.get() {
            return parts.source.documents_validator(collection, joins);
        }
        if joins {
            return self.create_documents_validator(collection).map(Arc::new);
        }
        let key = format!(
            "{}::{}::{}",
            self.collection_metadata_cache_key(collection.id()),
            self.max_query_values(),
            collection.fingerprint()?
        );
        if let Some(v) = self.settings().documents_validators.get(&key) {
            return Ok(v.clone());
        }
        let validator = Arc::new(self.create_documents_validator(collection)?);
        let mut settings = self.settings();
        if settings.documents_validators.len() >= 256 {
            settings.documents_validators.clear();
        }
        settings.documents_validators.insert(key, validator.clone());
        Ok(validator)
    }

    /// `createDocumentsValidator()`.
    fn create_documents_validator(&self, collection: &Collection) -> Result<DocumentsValidator> {
        Ok(DocumentsValidator::new(
            collection.attributes()?,
            collection.indexes()?,
            &self.profile(),
            self.max_query_values(),
        ))
    }

    /// The validator of a query list (`getQueriesValidator()`): a narrow
    /// list's own, else the collection's documents validator.
    pub(crate) fn queries_validator(
        &self,
        collection: &Collection,
        queries: &[Query],
        joins: bool,
    ) -> Result<QueriesValidator> {
        if let Some(parts) = self.inner.mirror.get() {
            return parts.source.queries_validator(collection, queries, joins);
        }
        if !joins
            && Narrow::accepts(queries)
            && let Some(narrow) =
                Narrow::of(queries, collection.attributes()?, &self.profile(), self.max_query_values())
        {
            return Ok(QueriesValidator::Narrow(narrow));
        }
        Ok(QueriesValidator::Documents(self.documents_validator(collection, joins)?))
    }

    /// Validates a read's queries (`validateDocumentsQueries()`).
    ///
    /// Fails ([`crate::Kind::Query`]) with the validator's description.
    pub(crate) async fn validate_documents_queries(
        &self,
        collection: &Collection,
        queries: &[Query],
        joined: Option<&Joined>,
    ) -> Result<()> {
        let resolved;
        let joined = match joined {
            Some(j) => j,
            None => {
                resolved = self.resolve_joined_collections(queries).await?;
                &resolved
            }
        };
        let validator = self.queries_validator(collection, queries, !joined.is_empty())?;
        if !joined.is_empty() {
            let documents: Vec<Document> = joined.iter().map(|(_, c)| c.document().clone()).collect();
            validator.set_joined_collections(&documents)?;
        }
        if !validator.check(queries) {
            return Err(Error::query(validator.description()));
        }
        Ok(())
    }

    /// The collections the join queries name, each read once, by the id the
    /// join names it with (`resolveJoinedCollections()`).
    ///
    /// Fails ([`crate::Kind::Query`]) for a missing one (`Joined collection
    /// '<id>' not found`).
    pub(crate) async fn resolve_joined_collections(&self, queries: &[Query]) -> Result<Joined> {
        let mut collections: Joined = Vec::new();
        for query in queries {
            if !query.method().is_join() {
                continue;
            }
            let id = query.attribute();
            if id.is_empty() || joined(&collections, id).is_some() {
                continue;
            }
            match self.silent(self.find_collection(id)).await? {
                Some(c) => collections.push((id.to_owned(), c)),
                None => return Err(Error::query(format!("Joined collection '{id}' not found"))),
            }
        }
        Ok(collections)
    }

    /// The joins with a condition on the parent side of a one-to-many
    /// relationship compared through the children's two-way key instead
    /// (`resolveOneToManyJoins()`).
    pub(crate) fn resolve_one_to_many_joins(collection: &Collection, mut queries: Vec<Query>) -> Result<Vec<Query>> {
        let mut keys: Option<Vec<(String, String)>> = None;
        for query in queries.iter_mut() {
            if !query.method().is_join() {
                continue;
            }
            let keys = match &keys {
                Some(k) => k,
                None => keys.insert(one_to_many_two_way_keys(collection)?),
            };
            if keys.is_empty() {
                return Ok(queries);
            }
            if !query.is_nested_join() {
                continue;
            }
            let mut changed = false;
            let mut values = utopia_query::Array::with_capacity(query.values().len());
            for (k, v) in query.values().clone() {
                let v = match v {
                    QValue::Ext(Object::Query(mut condition)) if condition.method() == Method::On => {
                        let left = match condition.values().get(&ArrayKey::Int(0)) {
                            Some(QValue::Str(s)) => s.as_str().map(str::to_owned),
                            _ => None,
                        };
                        if let Some(two_way) = left.and_then(|l| keys.iter().find(|(k, _)| *k == l).map(|(_, t)| t)) {
                            let mut cv = condition.values().clone();
                            cv.set(ArrayKey::Int(0), QValue::Str(Str::from(document::ID)));
                            cv.set(ArrayKey::Int(2), QValue::Str(Str::from(two_way.as_str())));
                            condition.set_values(cv);
                            changed = true;
                        }
                        QValue::Ext(Object::Query(condition))
                    }
                    other => other,
                };
                values.set(k, v);
            }
            if changed {
                query.set_values(values);
            }
        }
        Ok(queries)
    }

    /// The filters and joins of a count, sum or bulk write, converted, with
    /// the collection the adapter reads and whether authorization can be
    /// skipped (`prepareFilterJoinQueries()`); `None` when a relationship
    /// filter matches no document.
    pub(crate) async fn prepare_filter_join_queries(
        &self,
        collection: &Collection,
        queries: &[Query],
        relationships: &[Attribute],
        collection_granted: bool,
        joined: Option<&Joined>,
    ) -> Result<Option<(Collection, Vec<Query>, bool)>> {
        let parsed = Query::group_by_type(queries)?;
        let filters = parsed.filters;
        let joins = parsed.joins;
        let mut adapter_collection = collection.clone();
        let mut resolved: Joined = Vec::new();
        if !joins.is_empty() {
            if !self.inner.adapter.supports(Capability::Joins) {
                return Err(Error::query("Join queries are not supported by this adapter"));
            }
            assert_join_count(joins.len())?;
            resolved = match joined {
                Some(j) => j.clone(),
                None => self.resolve_joined_collections(&joins).await?,
            };
            let security = self.authorize_joins(&joins, PermissionType::Read, &resolved)?;
            adapter_collection = self.with_join_indexed(
                with_join_authorization(adapter_collection, &security, collection_granted)?,
                &joins,
                &resolved,
            )?;
        }
        let empty = joins.is_empty();
        let mut all = filters;
        all.extend(joins.iter().cloned());
        if !all.is_empty() {
            let by_alias = joined_by_alias(&joins, &resolved);
            all = self.convert_queries(&adapter_collection, all, &by_alias)?;
        }
        let converted = match self.relationship_hook() {
            Some(hook) => hook.convert_queries(relationships, all, Some(&adapter_collection)).await?,
            None => Some(all),
        };
        Ok(converted.map(|q| (adapter_collection, q, collection_granted && empty)))
    }

    /// Authorizes each joined collection once (`authorizeJoins()`): the
    /// tables whose rows the adapter filters per document, by every name
    /// the adapter may give them.
    ///
    /// Fails ([`crate::Kind::Authorization`]) for a joined collection the
    /// caller cannot read without document security.
    pub(crate) fn authorize_joins(
        &self,
        joins: &[Query],
        for_permission: PermissionType,
        joined_collections: &Joined,
    ) -> Result<Vec<(String, bool)>> {
        let authorization = self.authorization();
        let mut security: Vec<(String, bool)> = Vec::new();
        let mut authorized: Vec<&str> = Vec::new();
        for join in joins {
            let id = join.attribute();
            if authorized.contains(&id) {
                continue;
            }
            authorized.push(id);
            let Some(collection) = joined(joined_collections, id) else {
                return Err(Error::query(format!("Joined collection '{id}' not found")));
            };
            let granted =
                authorization.is_valid(&Input::new(for_permission, collection.permissions_by_type(for_permission)?));
            let document_security = collection.get(crate::collection::DOCUMENT_SECURITY).is_some_and(Value::truthy);
            if !granted && !document_security {
                return Err(Error::authorization(format!("Unauthorized access to joined collection '{id}'")));
            }
            let keys = [
                id.to_owned(),
                collection.id().to_owned(),
                crate::adapter::filter(id),
                crate::adapter::filter(collection.id()),
            ];
            let mut seen: Vec<&String> = Vec::new();
            for key in keys.iter() {
                if key.is_empty() || seen.contains(&key) {
                    continue;
                }
                seen.push(key);
                match security.iter_mut().find(|(k, _)| k == key) {
                    Some(entry) => entry.1 = !granted,
                    None => security.push((key.clone(), !granted)),
                }
            }
        }
        Ok(security)
    }

    /// The collection with, under shared tables, the attributes each joined
    /// collection's lookup indexes lead with (`withJoinIndexed()`).
    pub(crate) fn with_join_indexed(
        &self,
        collection: Collection,
        joins: &[Query],
        joined_collections: &Joined,
    ) -> Result<Collection> {
        if joins.is_empty() || !self.inner.adapter.shared_tables() {
            return Ok(collection);
        }
        let mut indexed: Vec<(String, Vec<String>)> = Vec::new();
        for join in joins {
            let id = join.attribute();
            if indexed.iter().any(|(k, _)| k == id) {
                continue;
            }
            let Some(joined) = joined(joined_collections, id) else { continue };
            let mut leads: Vec<String> = Vec::new();
            for index in joined.indexes()? {
                if LOOKUP_INDEX_TYPES.contains(&index.kind)
                    && let Some(first) = index.attributes.first()
                    && !leads.contains(first)
                {
                    leads.push(first.clone());
                }
            }
            indexed.push((id.to_owned(), leads));
        }
        let value: Array = indexed
            .into_iter()
            .map(|(k, leads)| {
                (
                    ArrayKey::normalize(Str::from(k)),
                    Value::Array(leads.into_iter().map(|l| Value::Str(Str::from(l))).collect()),
                )
            })
            .collect();
        let mut collection = collection;
        collection.set(super::super::JOIN_INDEXED, Value::Array(value))?;
        Ok(collection)
    }

    /// The collection with the attributes a join without a select returns
    /// under its alias, relationships left out (`withJoinAttributes()`).
    pub(crate) fn with_join_attributes(
        collection: Collection,
        joins: &[Query],
        joined_collections: &Joined,
    ) -> Result<Collection> {
        if joins.is_empty() {
            return Ok(collection);
        }
        let mut attributes: Vec<(String, Vec<String>)> = Vec::new();
        for join in joins {
            let id = join.attribute();
            if attributes.iter().any(|(k, _)| k == id) {
                continue;
            }
            let mut keys = Vec::new();
            if let Some(joined) = joined(joined_collections, id) {
                for attribute in joined.attributes()? {
                    if attribute.relationship.is_none() {
                        keys.push(attribute.key.clone());
                    }
                }
            }
            attributes.push((id.to_owned(), keys));
        }
        let value: Array = attributes
            .into_iter()
            .map(|(k, keys)| {
                (
                    ArrayKey::normalize(Str::from(k)),
                    Value::Array(keys.into_iter().map(|l| Value::Str(Str::from(l))).collect()),
                )
            })
            .collect();
        let mut collection = collection;
        collection.set(super::super::JOIN_ATTRIBUTES, Value::Array(value))?;
        Ok(collection)
    }

    /// The selections a read decodes: the selected attributes, relationship
    /// paths, and the internal attributes (`validateSelections()`).
    ///
    /// Fails ([`crate::Kind::Query`]) for a select value that is not a
    /// string, and for unknown attributes on an adapter that defines them
    /// (`Cannot select attributes: <keys>`).
    pub(crate) fn validate_selections(
        &self,
        collection: &Collection,
        queries: &[Query],
        distinct: bool,
    ) -> Result<Vec<String>> {
        if queries.is_empty() {
            return Ok(Vec::new());
        }
        let mut selections: Vec<String> = Vec::new();
        let mut relationship_selections: Vec<String> = Vec::new();
        for query in queries {
            if query.method() != Method::Select {
                continue;
            }
            for value in query.values().values() {
                let QValue::Str(s) = value else {
                    return Err(Error::query("Select queries must contain only string attributes."));
                };
                let s = String::from_utf8_lossy(s.as_bytes()).into_owned();
                if s.contains('.') {
                    relationship_selections.push(s);
                } else {
                    selections.push(s);
                }
            }
        }
        let mut keys: Vec<&str> = self.internal_attributes().iter().map(|a| a.key.as_str()).collect();
        for attribute in collection.attributes()? {
            if attribute.relationship.is_none() {
                keys.push(attribute.key.as_str());
            }
        }
        if self.inner.adapter.supports(Capability::DefinedAttributes) {
            let invalid: Vec<&String> = selections.iter().filter(|s| !keys.contains(&s.as_str())).collect();
            if !invalid.is_empty() && !invalid.iter().any(|s| *s == "*") {
                let mut unique: Vec<&str> = Vec::new();
                for s in &invalid {
                    unique.push(s.as_str());
                }
                return Err(Error::query(format!("Cannot select attributes: {}", unique.join(", "))));
            }
        }
        selections.extend(relationship_selections);
        // A distinct row is not a stored document: it carries only the
        // attributes it selects (8.3.4).
        if distinct {
            let mut unique: Vec<String> = Vec::with_capacity(selections.len());
            for s in selections {
                if !unique.contains(&s) {
                    unique.push(s);
                }
            }
            return Ok(unique);
        }
        for internal in [
            document::ID,
            document::SEQUENCE,
            document::COLLECTION,
            document::CREATED_AT,
            document::UPDATED_AT,
            document::PERMISSIONS,
        ] {
            selections.push(internal.to_owned());
        }
        let mut unique: Vec<String> = Vec::with_capacity(selections.len());
        for s in selections {
            if !unique.contains(&s) {
                unique.push(s);
            }
        }
        Ok(unique)
    }

    /// Whether the caller holds `permission` on the collection itself.
    pub(crate) fn collection_granted(&self, collection: &Collection, permission: PermissionType) -> Result<bool> {
        Ok(self.authorization().is_valid(&Input::new(permission, collection.permissions_by_type(permission)?)))
    }
}

/// A batch size of a bulk read or write (`batchSize()`): at most
/// [`super::super::BATCH_SIZE`], at least 1.
///
/// Fails ([`crate::Kind::Limit`]) above it (`Batch size must be at most
/// 1000, got <n>`).
pub(crate) fn batch_size(batch_size: i64) -> Result<i64> {
    if batch_size > super::super::BATCH_SIZE {
        return Err(Error::limit(format!("Batch size must be at most {}, got {batch_size}", super::super::BATCH_SIZE)));
    }
    Ok(batch_size.max(1))
}

/// `assertMax()`.
///
/// Fails ([`crate::Kind::Query`]) for a max that is not greater than 0
/// (`Max must be greater than 0`).
pub(crate) fn assert_max(max: Option<i64>) -> Result<()> {
    if max.is_some_and(|m| m <= 0) {
        return Err(Error::query("Max must be greater than 0"));
    }
    Ok(())
}

/// The two-way key of each one-to-many relationship the collection is the
/// parent of (`oneToManyTwoWayKeys()`).
fn one_to_many_two_way_keys(collection: &Collection) -> Result<Vec<(String, String)>> {
    let mut keys = Vec::new();
    for attribute in collection.attributes()? {
        let Some(relationship) = &attribute.relationship else { continue };
        if relationship.kind == RelationshipType::OneToMany
            && attribute.side == Some(RelationshipSide::Parent)
            && let Some(two_way) = relationship.two_way_key.as_ref().filter(|k| !k.is_empty())
        {
            keys.push((attribute.key.clone(), two_way.clone()));
        }
    }
    Ok(keys)
}

/// The join cap holds without validation too (`assertJoinCount()`).
///
/// Fails ([`crate::Kind::Query`]) beyond it.
pub(crate) fn assert_join_count(count: usize) -> Result<()> {
    let validator = JoinValidator::new(None, true);
    if !validator.is_valid_count(count) {
        return Err(Error::query(validator.description()));
    }
    Ok(())
}

/// The collection the adapter reads for a join, with whether the caller
/// holds the collection-level permission and which joined tables are
/// filtered per document (`withJoinAuthorization()`).
pub(crate) fn with_join_authorization(
    collection: Collection,
    security: &[(String, bool)],
    collection_granted: bool,
) -> Result<Collection> {
    if security.is_empty() {
        return Ok(collection);
    }
    let mut collection = collection;
    collection.set(super::super::COLLECTION_GRANTED, Value::Bool(collection_granted))?;
    let map: Array =
        security.iter().map(|(k, v)| (ArrayKey::normalize(Str::from(k.as_str())), Value::Bool(*v))).collect();
    collection.set(super::super::JOIN_DOCUMENT_SECURITY, Value::Array(map))?;
    Ok(collection)
}

/// The collection each join reads, by the alias its values come back under
/// (`joinedCollectionsByAlias()`).
pub(crate) fn joined_by_alias(joins: &[Query], joined_collections: &Joined) -> Joined {
    let mut out: Joined = Vec::new();
    for join in joins {
        let collection = joined(joined_collections, join.attribute()).cloned().unwrap_or_default();
        let alias = join.alias().to_owned();
        match out.iter_mut().find(|(a, _)| *a == alias) {
            Some(entry) => entry.1 = collection,
            None => out.push((alias, collection)),
        }
    }
    out
}

/// The `alias.$id` of each outer join a select names attributes of without
/// its `$id` or `alias.*` (`outerJoinIdSelections()`).
pub(crate) fn outer_join_id_selections(selects: &[Query], joins: &[Query], joined_collections: &Joined) -> Vec<String> {
    if selects.is_empty() || joined_collections.is_empty() {
        return Vec::new();
    }
    if !joins.iter().any(|j| matches!(j.method(), Method::LeftJoin | Method::RightJoin | Method::FullOuterJoin)) {
        return Vec::new();
    }
    let mut aliases: Vec<(String, Vec<String>)> = Vec::new();
    for select in selects {
        for value in select.values().values() {
            let QValue::Str(s) = value else { continue };
            let s = String::from_utf8_lossy(s.as_bytes());
            if s == "*" {
                return Vec::new();
            }
            if let Some(dot) = s.find('.') {
                let (alias, attribute) = (&s[..dot], &s[dot + 1..]);
                match aliases.iter_mut().find(|(a, _)| a == alias) {
                    Some(entry) => entry.1.push(attribute.to_owned()),
                    None => aliases.push((alias.to_owned(), vec![attribute.to_owned()])),
                }
            }
        }
    }
    aliases
        .into_iter()
        .filter(|(alias, attributes)| {
            joined(joined_collections, alias).is_some() && !attributes.iter().any(|a| a == document::ID || a == "*")
        })
        .map(|(alias, _)| format!("{alias}.{}", document::ID))
        .collect()
}
