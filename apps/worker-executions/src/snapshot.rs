//! The rows `Appwrite\Execution\Store` writes for a message on the executions queue.
//!
//! Each function names the PHP it ports. Casts, date parsing and JSON encoding go through php-std,
//! so a row written here reads back through `Store::get()` and `Store::find()` as one the PHP
//! worker wrote: `createdAt` and `updatedAt` are compared as strings, and `document` is what the
//! API returns.

use std::time::{SystemTime, UNIX_EPOCH};

use php_std::datetime::{DateTime, TzInfo};
use php_std::format::{dval_to_lval, str_to_int};
use php_std::json::{self, Flags};
use php_std::{Array, ArrayKey, Str, Value};
use serde_json::{Map, Value as Json, json};
use utopia_database::datetime;

/// `Store::VERSION_SHIFT`: the rank is the version's top bits.
const VERSION_SHIFT: u32 = 60;
/// `Store::VERSION_DELETE_RANK`: a delete outranks every status.
const DELETE_RANK: usize = 7;

/// `Store::VERSION_STATUS_RANKS`, and 2 for a status it does not name: a later status outranks an
/// earlier one whichever is written last.
fn rank(status: &str) -> usize {
    match status {
        "scheduled" | "waiting" => 1,
        "completed" | "failed" => 3,
        _ => 2,
    }
}

/// The versions given so far, per rank (`Store::$lastVersions`).
#[derive(Default)]
pub struct Versions([u64; DELETE_RANK + 1]);

impl Versions {
    /// The rank over the time in microseconds, and later than any this process gave the rank
    /// before. PHP: `Store::nextVersion()`.
    fn next(&mut self, rank: usize) -> u64 {
        let micros = SystemTime::now().duration_since(UNIX_EPOCH).map_or(0, |since| since.as_micros() as u64);
        let version = ((rank as u64) << VERSION_SHIFT) + micros;
        self.0[rank] = version.max(self.0[rank] + 1);
        self.0[rank]
    }
}

/// The rows a message writes, or why it can write none: retrying will not change that.
///
/// PHP: `Workers\Executions::action()`, then `Store::upsertMany()` or `Store::delete()`.
pub fn rows(payload: &Json, retention: i64, versions: &mut Versions) -> Result<Vec<Map<String, Json>>, String> {
    // As the PHP worker has it, from `json_decode($body, true)`: an empty object is an empty array.
    let body = serde_json::to_vec(payload).map_err(|error| error.to_string())?;
    let payload = match json::decode(&body, Some(true), json::DEFAULT_DEPTH, Flags::NONE) {
        Ok(Value::Array(payload)) => payload,
        Ok(_) => return Err("The payload is not an object".to_string()),
        Err(error) => return Err(error.message().to_string()),
    };
    let project = document(payload.get_str("project"))?;
    let project = text(project.get_str("$id"))?;

    let deleted =
        matches!(payload.get_str("operation"), Some(Value::Str(operation)) if operation.as_bytes() == b"delete");
    let (executions, missing) = match payload.get_str("executions") {
        Some(Value::Array(executions)) if !deleted => (
            executions
                .values()
                .map(|execution| match execution {
                    Value::Array(_) => document(Some(execution)),
                    other => Err(format!("An execution must be an array, not {}", other.type_name())),
                })
                .collect::<Result<Vec<_>, _>>()?,
            "Missing executions",
        ),
        _ => (vec![document(payload.get_str("execution"))?], "Missing execution"),
    };
    let executions: Vec<Array> = executions.into_iter().filter(|execution| !execution.is_empty()).collect();
    if executions.is_empty() {
        return Err(missing.to_string());
    }

    executions.into_iter().map(|execution| snapshot(&project, execution, deleted, retention, versions)).collect()
}

/// The array `new Document($value ?? [])` holds: its permissions without duplicates.
fn document(value: Option<&Value>) -> Result<Array, String> {
    let mut document = match value {
        None | Some(Value::Null) => return Ok(Array::new()),
        Some(Value::Array(document)) => document.clone(),
        Some(other) => return Err(format!("A document must be an array, not {}", other.type_name())),
    };
    if document.get_str("$id").is_some_and(|id| !matches!(id, Value::Str(_))) {
        return Err("$id must be of type string".to_string());
    }
    if let Some(permissions) = document.get_str("$permissions") {
        let Value::Array(permissions) = permissions else {
            return Err("$permissions must be of type array".to_string());
        };
        let mut unique: Vec<Str> = Vec::new();
        for permission in permissions.values() {
            let Value::Str(permission) = permission else {
                return Err("Every permission must be of type string".to_string());
            };
            if !unique.contains(permission) {
                unique.push(permission.clone());
            }
        }
        let unique = unique.into_iter().map(Value::Str).collect();
        set(&mut document, "$permissions", Value::Array(unique));
    }
    Ok(document)
}

/// The row of one execution. PHP: `Store::snapshot()`.
fn snapshot(
    project: &str,
    mut execution: Array,
    deleted: bool,
    retention: i64,
    versions: &mut Versions,
) -> Result<Map<String, Json>, String> {
    let created_at = match present(execution.get_str("$createdAt"))? {
        Some(created_at) => created_at,
        None => {
            let now = datetime::now();
            set(&mut execution, "$createdAt", Value::Str(Str::from(now.as_str())));
            now
        }
    };
    let updated_at = match present(execution.get_str("$updatedAt"))? {
        Some(updated_at) => updated_at,
        None => {
            set(&mut execution, "$updatedAt", Value::Str(Str::from(created_at.as_str())));
            created_at.clone()
        }
    };
    let mut sequence = int(execution.get_str("$sequence"));
    if sequence <= 0 {
        sequence = sequence_from(&created_at);
        set(&mut execution, "$sequence", Value::Int(sequence));
    }
    public_timestamps(&mut execution);

    let permissions: Vec<String> = match execution.get_str("$permissions") {
        Some(Value::Array(permissions)) => {
            permissions.values().map(|permission| text(Some(permission))).collect::<Result<_, _>>()?
        }
        _ => Vec::new(),
    };
    let mut read_roles: Vec<&str> = Vec::new();
    for role in permissions.iter().filter_map(|permission| read_role(permission)) {
        if !read_roles.contains(&role) {
            read_roles.push(role);
        }
    }
    let attribute = |key: &str| text(execution.get_str(key));
    let status = attribute("status")?;
    let document = json::encode(&Value::Array(execution.clone()), Flags::THROW_ON_ERROR, json::DEFAULT_DEPTH)
        .map_err(|error| error.message().to_string())?;
    let version = versions.next(if deleted { DELETE_RANK } else { rank(&status) });

    let row = json!({
        "projectId": project,
        "id": attribute("$id")?,
        "createdAt": date(&created_at)?,
        "updatedAt": date(&updated_at)?,
        "sequence": sequence,
        "permissions": permissions,
        "readRoles": read_roles,
        "resourceInternalId": attribute("resourceInternalId")?,
        "resourceId": attribute("resourceId")?,
        "resourceType": attribute("resourceType")?,
        "deploymentInternalId": attribute("deploymentInternalId")?,
        "deploymentId": attribute("deploymentId")?,
        "trigger": attribute("trigger")?,
        "status": status,
        "responseStatusCode": int(execution.get_str("responseStatusCode")),
        "duration": execution.get_str("duration").map_or(0.0, Value::to_double),
        "requestMethod": attribute("requestMethod")?,
        "requestPath": attribute("requestPath")?,
        "document": document,
        "expiresAt": expires_at(&created_at, deleted, retention),
        "deleted": u8::from(deleted),
        "version": version,
    });
    match row {
        Json::Object(row) => Ok(row),
        _ => unreachable!("json! of an object literal is an object"),
    }
}

/// A timestamp attribute, or `None` where PHP's `=== null || === ''` gives it a default.
fn present(value: Option<&Value>) -> Result<Option<String>, String> {
    match value {
        None | Some(Value::Null) => Ok(None),
        Some(Value::Str(value)) if value.is_empty() => Ok(None),
        value => text(value).map(Some),
    }
}

/// The role a `read("…")` permission grants: the regex `/^read\("(.+)"\)$/`, whose `$` also
/// matches before a final newline.
fn read_role(permission: &str) -> Option<&str> {
    [Some(permission), permission.strip_suffix('\n')].into_iter().flatten().find_map(|candidate| {
        candidate.strip_prefix("read(\"")?.strip_suffix("\")").filter(|role| !role.is_empty() && !role.contains('\n'))
    })
}

/// The API's timestamps with their offset, as the stored document carries them.
/// PHP: `Store::publicTimestamps()`.
fn public_timestamps(execution: &mut Array) {
    for key in ["$createdAt", "$updatedAt", "scheduledAt"] {
        let Some(Value::Str(value)) = execution.get_str(key) else {
            continue;
        };
        let Some(value) = value.as_str().filter(|value| !value.is_empty()) else {
            continue;
        };
        if let Some(formatted) = datetime::format_tz(Some(value)) {
            set(execution, key, Value::Str(Str::from(formatted.as_str())));
        }
    }
}

/// In UTC, in the database format. PHP: `Store::date()`.
fn date(value: &str) -> Result<String, String> {
    datetime::set_timezone(value).map_err(|error| error.to_string())
}

/// PHP: `Store::expiresAt()`.
fn expires_at(created_at: &str, deleted: bool, retention: i64) -> String {
    let mut date = parse(if deleted { "now" } else { created_at });
    let _ = date.modify(format!("+{} seconds", retention.max(0)).as_bytes());
    datetime::format(&date)
}

/// Microseconds since the epoch. PHP: `Store::sequenceFromCreatedAt()`.
fn sequence_from(created_at: &str) -> i64 {
    let date = parse(created_at);
    date.timestamp() * 1_000_000 + date.microsecond()
}

/// `new \DateTime($value)`, and now where that throws.
fn parse(value: &str) -> DateTime {
    let utc = TzInfo::utc();
    DateTime::new(value.as_bytes(), None, &utc).or_else(|_| DateTime::new(b"now", None, &utc)).expect("now parses")
}

/// `$document->setAttribute($key, $value)`: in place, or last when the key is new.
fn set(document: &mut Array, key: &str, value: Value) {
    document.set(ArrayKey::normalize(Str::from(key)), value);
}

/// `(string)`, where a missing attribute is `''`.
fn text(value: Option<&Value>) -> Result<String, String> {
    let Some(value) = value else {
        return Ok(String::new());
    };
    value
        .to_php_string()
        .map(|text| String::from_utf8_lossy(text.as_bytes()).into_owned())
        .map_err(|error| error.to_string())
}

/// `(int)`, where a missing attribute is 0.
fn int(value: Option<&Value>) -> i64 {
    match value {
        None | Some(Value::Null) | Some(Value::Bool(false)) => 0,
        Some(Value::Bool(true)) | Some(Value::Object(_)) | Some(Value::Ext(_)) => 1,
        Some(Value::Int(value)) => *value,
        Some(Value::Float(value)) => dval_to_lval(*value),
        Some(Value::Str(value)) => str_to_int(value.as_bytes()),
        Some(Value::Array(value)) => i64::from(!value.is_empty()),
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    const RETENTION: i64 = 1_209_600;

    fn one(payload: Json) -> Map<String, Json> {
        let mut rows = rows(&payload, RETENTION, &mut Versions::default()).unwrap();
        assert_eq!(rows.len(), 1);
        rows.remove(0)
    }

    fn execution(status: &str) -> Json {
        json!({
            "$id": "exec1",
            "$createdAt": "2026-11-28T10:00:00.123+02:00",
            "$updatedAt": "2026-11-28 08:00:01.5",
            "$permissions": ["read(\"user:a\")", "read(\"user:a\")", "read(\"any\")", "update(\"user:a\")"],
            "resourceInternalId": 12,
            "resourceId": "fn1",
            "resourceType": "functions",
            "deploymentId": "dep1",
            "trigger": "http",
            "status": status,
            "responseStatusCode": "200",
            "duration": 0.25,
            "requestMethod": "GET",
            "requestPath": "/",
            "logs": "",
            "headers": {},
        })
    }

    #[test]
    fn a_row_holds_what_the_store_reads() {
        let row = one(json!({"project": {"$id": "p1"}, "execution": execution("completed")}));

        assert_eq!(row["projectId"], "p1");
        assert_eq!(row["id"], "exec1");
        // Stored in UTC, as the strings Store::find() orders and filters by.
        assert_eq!(row["createdAt"], "2026-11-28 08:00:00.123");
        assert_eq!(row["updatedAt"], "2026-11-28 08:00:01.500");
        assert_eq!(row["permissions"], json!(["read(\"user:a\")", "read(\"any\")", "update(\"user:a\")"]));
        assert_eq!(row["readRoles"], json!(["user:a", "any"]));
        assert_eq!(row["resourceInternalId"], "12");
        assert_eq!(row["deploymentInternalId"], "");
        assert_eq!(row["responseStatusCode"], 200);
        assert_eq!(row["duration"], 0.25);
        assert_eq!(row["deleted"], 0);
        // Retention from createdAt, in createdAt's own offset as PHP formats it.
        assert_eq!(row["expiresAt"], "2026-12-12 10:00:00.123");
        // The sequence it lacked is createdAt in microseconds, and the document carries it.
        assert_eq!(row["sequence"], 1_795_852_800_123_000_i64);
    }

    #[test]
    fn the_document_is_what_php_encodes() {
        let row = one(json!({"project": {"$id": "p1"}, "execution": execution("completed")}));
        let document: Json = serde_json::from_str(row["document"].as_str().unwrap()).unwrap();

        assert_eq!(document["$createdAt"], "2026-11-28T10:00:00.123+02:00");
        assert_eq!(document["$updatedAt"], "2026-11-28T08:00:01.500+00:00");
        assert_eq!(document["$sequence"], 1_795_852_800_123_000_i64);
        // json_decode($body, true) makes {} an empty array, which json_encode writes as [].
        assert_eq!(document["headers"], json!([]));
        let text = row["document"].as_str().unwrap();
        assert!(text.contains("\"read(\\\"any\\\")\""), "{text}");
        assert!(text.contains("\"requestPath\":\"\\/\""), "slashes are escaped: {text}");
    }

    #[test]
    fn a_later_status_outranks_an_earlier_one_written_after_it() {
        let mut versions = Versions::default();
        let payload = |status| json!({"project": {"$id": "p1"}, "execution": execution(status)});
        let completed = &rows(&payload("completed"), RETENTION, &mut versions).unwrap()[0];
        let processing = &rows(&payload("processing"), RETENTION, &mut versions).unwrap()[0];
        let deleted = &rows(
            &json!({"operation": "delete", "project": {"$id": "p1"}, "execution": execution("waiting")}),
            RETENTION,
            &mut versions,
        )
        .unwrap()[0];

        let version = |row: &Map<String, Json>| row["version"].as_u64().unwrap();
        assert!(version(completed) > version(processing));
        assert!(version(deleted) > version(completed));
        assert_eq!(deleted["deleted"], 1);
    }

    #[test]
    fn a_batch_writes_every_execution_it_holds() {
        let rows = rows(
            &json!({"project": {"$id": "p1"}, "executions": [execution("waiting"), {}, execution("failed")]}),
            RETENTION,
            &mut Versions::default(),
        )
        .unwrap();

        let statuses: Vec<&Json> = rows.iter().map(|row| &row["status"]).collect();
        assert_eq!(statuses, ["waiting", "failed"]);
    }

    #[test]
    fn a_message_without_an_execution_writes_nothing() {
        let mut versions = Versions::default();
        let project = json!({"$id": "p1"});
        let fails = |payload: Json| rows(&payload, RETENTION, &mut Versions::default()).unwrap_err();

        assert_eq!(fails(json!({"project": project})), "Missing execution");
        assert_eq!(fails(json!({"project": project, "executions": [{}]})), "Missing executions");
        assert_eq!(fails(json!({"project": project, "operation": "delete"})), "Missing execution");
        assert!(rows(&json!({"project": project, "execution": {"$id": 7}}), RETENTION, &mut versions).is_err());
    }

    #[test]
    fn a_read_role_is_what_the_pattern_captures() {
        assert_eq!(read_role("read(\"team:t/owner\")"), Some("team:t/owner"));
        assert_eq!(read_role("read(\"a\")\n"), Some("a"));
        assert_eq!(read_role("read(\"\")"), None);
        assert_eq!(read_role("write(\"a\")"), None);
    }
}
