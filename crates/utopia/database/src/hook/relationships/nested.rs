//! `prepareNested()`: the nested related documents of a write given to the
//! nested hooks, and the permissions they grant checked.

use super::{Preparation, Relationships, relationships, to_document};
use crate::collection::Collection;
use crate::database::{BoxFuture, Database, RELATION_MAX_DEPTH};
use crate::document::{self, Document};
use crate::error::{Error, Result};
use crate::permission::{Permission, PermissionType};
use crate::query::Query;
use crate::validator::permissions::Permissions as PermissionsValidator;
use crate::value::{self, Ext, Value};

/// A related document given as a document or as an array that is not a
/// list (`isDocumentValue()`).
fn is_document_value(value: &Value) -> bool {
    match value {
        Value::Ext(Ext::Document(_)) => true,
        Value::Array(a) => !a.is_list(),
        _ => false,
    }
}

/// The actions `Permission::aggregate()` expands to by default.
const AGGREGATED: [PermissionType; 4] =
    [PermissionType::Create, PermissionType::Read, PermissionType::Update, PermissionType::Delete];

impl Relationships {
    /// Each nested related document of a write prepared by the nested hooks,
    /// and its grants checked, as deep as the write relates it
    /// (`prepareNested()`). The relationship values are replaced with the
    /// prepared copies. A write nested in another relationship write is left
    /// as given: the outermost write prepared it.
    ///
    /// Fails ([`crate::Kind::Authorization`]) when a related document adds a
    /// permission for a role the caller does not hold,
    /// ([`crate::Kind::Relationship`]) when a related document given as an
    /// array is not a valid document, ([`crate::Kind::Structure`]) when a
    /// related document's permissions are malformed.
    pub(crate) fn prepare_nested<'a>(
        &'a self,
        collection: &'a Collection,
        document: Document,
        preparation: &'a Preparation,
    ) -> BoxFuture<'a, Result<Document>> {
        Box::pin(async move {
            let mut document = document;
            if !self.current_write_stack().is_empty() {
                return Ok(document);
            }
            let db = self.database()?;
            for (key, value) in self.prepare_nested_values(&db, collection, &document, &[], preparation).await? {
                document.put(key, value);
            }
            Ok(document)
        })
    }

    /// The prepared relationship values of `document` (`prepareNestedValues()`).
    fn prepare_nested_values<'a>(
        &'a self,
        db: &'a Database,
        collection: &'a Collection,
        document: &'a Document,
        write_stack: &'a [String],
        preparation: &'a Preparation,
    ) -> BoxFuture<'a, Result<Vec<(String, Value)>>> {
        Box::pin(async move {
            let depth = write_stack.len();
            let mut prepared = Vec::new();

            for attribute in relationships(collection)? {
                let Some(relationship) = &attribute.relationship else { continue };
                let value = document.get(&attribute.key).cloned().unwrap_or(Value::Null);
                let list = matches!(&value, Value::Array(a) if a.is_list());
                let mut values: Vec<Value> = match &value {
                    Value::Array(a) if list => a.values().cloned().collect(),
                    _ => vec![value.clone()],
                };
                if !values.iter().any(is_document_value) {
                    continue;
                }

                let related_collection = db.get_collection(&relationship.related_collection).await?;
                if depth as i64 >= RELATION_MAX_DEPTH - 1 && write_stack[depth - 1] != related_collection.id() {
                    continue;
                }

                let mut stack = write_stack.to_vec();
                stack.push(collection.id().to_owned());
                for nested in values.iter_mut() {
                    if !is_document_value(nested) {
                        continue;
                    }
                    let given = match nested {
                        Value::Ext(Ext::Document(d)) => (**d).clone(),
                        Value::Array(a) => to_document(a.clone())?,
                        _ => unreachable!("a document value"),
                    };
                    let document =
                        self.prepare_nested_document(db, &related_collection, given, &stack, preparation).await?;
                    *nested = Value::from(document);
                }

                let value = if list { value::list(values) } else { values.swap_remove(0) };
                prepared.push((attribute.key.clone(), value));
            }

            Ok(prepared)
        })
    }

    /// One nested related document given to the nested hooks, its grants
    /// checked, and its own related documents prepared
    /// (`prepareNestedDocument()`).
    async fn prepare_nested_document(
        &self,
        db: &Database,
        collection: &Collection,
        document: Document,
        write_stack: &[String],
        preparation: &Preparation,
    ) -> Result<Document> {
        let given = document.id().to_owned();
        let mut document = document;
        for hook in &preparation.hooks {
            document = hook.prepare(collection, document).await?;
        }

        if preparation.check {
            let id = document.id();
            let new = id.is_empty() || id != given;
            self.authorize_grants(db, collection, &document, new, preparation).await?;
        }

        for (key, value) in self.prepare_nested_values(db, collection, &document, write_stack, preparation).await? {
            document.put(key, value);
        }

        Ok(document)
    }

    /// Checks the permissions a nested document adds are for roles the
    /// caller holds (`authorizeGrants()`): all of them for a `new` one, else
    /// those its granted view does not hold already.
    async fn authorize_grants(
        &self,
        db: &Database,
        collection: &Collection,
        document: &Document,
        new: bool,
        preparation: &Preparation,
    ) -> Result<()> {
        let Some(permissions) = document.get(document::PERMISSIONS) else { return Ok(()) };

        let validator = PermissionsValidator::default();
        if !validator.is_valid(permissions) {
            return Err(Error::structure(validator.description()));
        }

        let current = if new {
            Vec::new()
        } else {
            let view = self.granted_view(db, collection, document.id(), preparation).await?;
            Permission::aggregate(&view.permissions()?, &AGGREGATED)?
        };
        let authorization = db.authorization();

        for granted in Permission::aggregate(&document.permissions()?, &AGGREGATED)? {
            if current.contains(&granted) {
                continue;
            }
            let permission = Permission::parse(&granted)?;
            if !authorization.has_role(&permission.role().to_string()) {
                let roles: Vec<String> = authorization.roles().iter().map(value::to_text).collect();
                return Err(Error::authorization(format!("Permissions must be one of: ({})", roles.join(", "))));
            }
        }
        Ok(())
    }

    /// The document `id`, whether or not the caller can read it, as the
    /// write that changes it will find it (`grantedView()`).
    async fn granted_view(
        &self,
        db: &Database,
        collection: &Collection,
        id: &str,
        preparation: &Preparation,
    ) -> Result<Document> {
        if let Some(staged) = preparation.resolve.as_ref().and_then(|resolve| resolve(collection.id(), id)) {
            return Ok(staged);
        }
        db.authorization()
            .skip(db.skip_relationships(db.skip_query_hooks(db.get_document(
                collection.id(),
                id,
                vec![Query::select([document::PERMISSIONS])],
                preparation.lock,
            ))))
            .await
    }
}
