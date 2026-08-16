//! Opt-in smoke test for the Postgres `dbForPlatform`/`dbForProject` wiring
//! (`AppwriteState::connect_from_env`) against a **real** shared Postgres --
//! the same one PHP Appwrite's Docker Compose stack uses. Not part of the
//! default `cargo test` run (task 7: keep the in-memory `users_http` suite
//! hermetic); run explicitly once a project/key exist on that Postgres:
//!
//! ```bash
//! # from the PHP Appwrite repo root, with the compose stack up:
//! docker compose exec appwrite-rust /bin/sh -lc '
//!   _APP_RUST_TEST_PROJECT_ID=<projectId> \
//!   _APP_RUST_TEST_PROJECT_KEY=<apiKeySecret> \
//!   cargo test -p appwrite-platform --test postgres_smoke -- --ignored --nocapture
//! '
//! ```
//!
//! `<projectId>`/`<apiKeySecret>` come from a project + standard API key
//! created via the PHP console/API (e.g. the same fixture
//! `tests/e2e/Services/Users` provisions), scoped to at least
//! `users.read`. `_APP_DB_ADAPTER`/`_APP_DB_HOST`/etc. are read from the
//! process env exactly like `apps/server`'s `main()` -- the compose
//! `appwrite-rust` service already sets these to match PHP.

use appwrite_platform::AppwriteState;

#[test]
#[ignore = "needs a live Postgres shared with PHP Appwrite plus a real project/key; see module docs"]
fn resolves_a_real_php_created_project_and_its_keys() {
    let project_id = match std::env::var("_APP_RUST_TEST_PROJECT_ID") {
        Ok(id) if !id.is_empty() => id,
        _ => {
            eprintln!(
                "skipping: set _APP_RUST_TEST_PROJECT_ID (and _APP_DB_ADAPTER=postgresql plus \
                 the usual _APP_DB_* env) to a project PHP already created"
            );
            return;
        }
    };

    let (state, adapter) = AppwriteState::connect_from_env();
    assert_eq!(
        adapter, "postgres",
        "expected _APP_DB_ADAPTER=postgresql to connect; check _APP_DB_HOST/_PORT/_USER/_PASS/_SCHEMA"
    );

    let project = state
        .resolve_project(&project_id)
        .expect("dbForPlatform should find the PHP-created project by $id");
    assert_eq!(
        project.get("$id").and_then(|v| v.as_str()),
        Some(project_id.as_str())
    );

    let keys = project
        .get("keys")
        .and_then(|v| v.as_array())
        .expect("project JSON should carry a `keys` array (subQueryKeys stand-in)");

    if let Ok(key_secret) = std::env::var("_APP_RUST_TEST_PROJECT_KEY") {
        let key = keys
            .iter()
            .find(|k| k.get("secret").and_then(|v| v.as_str()) == Some(key_secret.as_str()))
            .expect(
                "expected the decrypted `secret` on one of the project's `keys` to match \
                 _APP_RUST_TEST_PROJECT_KEY -- if this fails but the project/key both exist, \
                 the `encrypt` filter (crates/appwrite-database/src/filters.rs) is not \
                 decrypting `keys.secret` the way PHP's OpenSSL envelope expects",
            );
        assert!(
            key.get("scopes")
                .and_then(|v| v.as_array())
                .is_some_and(|scopes| !scopes.is_empty()),
            "matched key should carry its real scopes list"
        );
    }

    // Namespace sanity check: dbForProject for this project should open
    // against `_<sequence>` without error, proving the connection +
    // namespace math (`Appwrite\Database\Factory::configureProject`) lines
    // up with what PHP already provisioned.
    let sequence = state
        .project_sequence(&project)
        .expect("PHP-created project documents always have a $sequence");
    let db = state
        .databases
        .get_or_create(&project_id, Some(&sequence))
        .expect("dbForProject should connect using the project's namespace");
    let mut db = db.lock();
    let users = db
        .find("users", &[], "read")
        .expect("users collection should already exist (PHP provisions it on project create)");
    println!(
        "dbForProject(_{sequence}) users collection has {} document(s)",
        users.len()
    );
}
