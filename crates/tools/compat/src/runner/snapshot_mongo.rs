//! The `mongo@<service>` snapshot: every MongoDB database named after the
//! namespace (`<ns>` and `<ns>_*`), on a service of `compat.json` (its
//! `runner` URL, `mongodb://user:password@host:port`, dialled directly).
//!
//! ```json
//! {
//!   "<database>": {
//!     "<collection>": {
//!       "indexes": {"<name>": {"key": {...}, "unique": true, ...}},
//!       "documents": [{"_id": ..., ...}, ...]
//!     }
//!   }
//! }
//! ```
//!
//! Databases, collections and indexes are sorted by name (`system.*`
//! collections left out), documents by `_id`; every value is canonical
//! extended JSON, so BSON types are compared too (`{"$numberInt": "1"}` is
//! not `{"$numberLong": "1"}`). Index specs keep every field but `ns`.
//! Cleanup drops the databases.
//!
//! The runner is synchronous and may itself run inside a Tokio runtime, so
//! each call runs on its own thread with its own runtime.

use futures_util::TryStreamExt;
use mongodb::bson::{Bson, Document, doc};
use mongodb::options::{ClientOptions, Credential, ServerAddress};
use serde_json::{Map, Value};

/// Runs `f` with a client of `url`, on a thread of its own.
fn with_client<T: Send + 'static>(
    url: &str,
    f: impl for<'c> FnOnce(&'c mongodb::Client) -> std::pin::Pin<Box<dyn Future<Output = Result<T, String>> + 'c>>
    + Send
    + 'static,
) -> Result<T, String> {
    let url = url.to_owned();
    std::thread::spawn(move || {
        let rt = tokio::runtime::Builder::new_current_thread().enable_all().build().map_err(|e| e.to_string())?;
        rt.block_on(async move {
            let client = mongodb::Client::with_options(options(&url)?).map_err(|e| format!("mongo {url}: {e}"))?;
            let out = f(&client).await;
            client.shutdown().await;
            out
        })
    })
    .join()
    .map_err(|_| "mongo snapshot thread panicked".to_owned())?
}

/// `mongodb://user:password@host:port` as direct-connection options.
fn options(url: &str) -> Result<ClientOptions, String> {
    let rest = url.strip_prefix("mongodb://").ok_or_else(|| format!("not a mongodb:// URL: {url}"))?;
    let rest = rest.split(['/', '?']).next().unwrap_or("");
    let (auth, host) = rest.rsplit_once('@').unwrap_or(("", rest));
    let (host, port) = host.rsplit_once(':').unwrap_or((host, "27017"));
    let mut options = ClientOptions::default();
    options.hosts = vec![ServerAddress::Tcp { host: host.to_owned(), port: port.parse().ok() }];
    options.direct_connection = Some(true);
    if let Some((user, password)) = auth.split_once(':') {
        let mut credential = Credential::default();
        credential.username = Some(user.to_owned());
        credential.password = Some(password.to_owned());
        credential.source = Some("admin".to_owned());
        options.credential = Some(credential);
    }
    Ok(options)
}

fn err(e: mongodb::error::Error) -> String {
    format!("mongo: {e}")
}

/// The databases of the namespace.
async fn databases(client: &mongodb::Client, ns: &str) -> Result<Vec<String>, String> {
    let mut names: Vec<String> = client
        .list_database_names()
        .await
        .map_err(err)?
        .into_iter()
        .filter(|n| n == ns || n.starts_with(&format!("{ns}_")))
        .collect();
    names.sort();
    Ok(names)
}

fn json(value: Bson) -> Value {
    value.into_canonical_extjson()
}

pub fn snapshot(url: &str, ns: &str) -> Result<Value, String> {
    let ns = ns.to_owned();
    with_client(url, move |client| {
        Box::pin(async move {
            let mut out = Map::new();
            for name in databases(client, &ns).await? {
                let db = client.database(&name);
                let mut collections: Vec<String> = db
                    .list_collection_names()
                    .await
                    .map_err(err)?
                    .into_iter()
                    .filter(|c| !c.starts_with("system."))
                    .collect();
                collections.sort();
                let mut tables = Map::new();
                for collection in collections {
                    let coll = db.collection::<Document>(&collection);
                    let mut indexes: Vec<(String, Value)> = Vec::new();
                    let mut cursor = db.run_cursor_command(doc! { "listIndexes": &collection }).await.map_err(err)?;
                    while let Some(mut spec) = cursor.try_next().await.map_err(err)? {
                        spec.remove("ns");
                        let name = spec.get_str("name").unwrap_or("").to_owned();
                        indexes.push((name, json(Bson::Document(spec))));
                    }
                    indexes.sort_by(|a, b| a.0.cmp(&b.0));
                    let documents: Vec<Document> = coll
                        .find(doc! {})
                        .sort(doc! { "_id": 1 })
                        .await
                        .map_err(err)?
                        .try_collect()
                        .await
                        .map_err(err)?;
                    let mut table = Map::new();
                    table.insert("indexes".into(), Value::Object(indexes.into_iter().collect()));
                    table.insert(
                        "documents".into(),
                        Value::Array(documents.into_iter().map(|d| json(Bson::Document(d))).collect()),
                    );
                    tables.insert(collection, Value::Object(table));
                }
                out.insert(name, Value::Object(tables));
            }
            Ok(Value::Object(out))
        })
    })
}

pub fn cleanup(url: &str, ns: &str) -> Result<(), String> {
    let ns = ns.to_owned();
    with_client(url, move |client| {
        Box::pin(async move {
            for name in databases(client, &ns).await? {
                client.database(&name).drop().await.map_err(err)?;
            }
            Ok(())
        })
    })
}
