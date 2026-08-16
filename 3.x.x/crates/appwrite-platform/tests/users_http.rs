//! End-to-end `/v1/users*` HTTP tests against the in-memory
//! [`appwrite_platform::build`] platform: exercises the `api`-group `Init`
//! hook's project/API-key resolution plus the Users module actions.

mod users_support;

use std::collections::HashMap;

use serde_json::{json, Value};
use utopia_http::{Request, Response};

use users_support::{
    body_json, boot, boot_with_scopes, create_user, create_user_id, map, run,
};

#[tokio::test]
async fn create_and_get_user_round_trip() {
    let h = boot().await;

    let mut payload = HashMap::new();
    payload.insert("userId".to_string(), json!("unique()"));
    payload.insert("email".to_string(), json!("user@example.com"));
    payload.insert("password".to_string(), json!("correcthorsebattery"));
    payload.insert("name".to_string(), json!("Ada Lovelace"));

    let res = run(&h.http, "POST", "/v1/users", payload).await;
    assert_eq!(
        res.status_code(),
        201,
        "create should return 201: {}",
        res.body_string()
    );

    let created = body_json(&res);
    let user_id = created["$id"]
        .as_str()
        .expect("created user has an $id")
        .to_string();
    assert_eq!(created["email"], json!("user@example.com"));
    assert_eq!(created["name"], json!("Ada Lovelace"));
    assert_eq!(created["status"], json!(true));

    let res = run(
        &h.http,
        "GET",
        &format!("/v1/users/{user_id}"),
        HashMap::new(),
    )
    .await;
    assert_eq!(
        res.status_code(),
        200,
        "get should return 200: {}",
        res.body_string()
    );
    let fetched = body_json(&res);
    assert_eq!(fetched["$id"], json!(user_id));
    assert_eq!(fetched["email"], json!("user@example.com"));

    let res = run(&h.http, "GET", "/v1/users", HashMap::new()).await;
    assert_eq!(res.status_code(), 200);
    let listed = body_json(&res);
    assert_eq!(listed["total"], json!(1));
    assert_eq!(listed["users"][0]["$id"], json!(user_id));

    let res = run(
        &h.http,
        "DELETE",
        &format!("/v1/users/{user_id}"),
        HashMap::new(),
    )
    .await;
    assert_eq!(res.status_code(), 204);

    let res = run(
        &h.http,
        "GET",
        &format!("/v1/users/{user_id}"),
        HashMap::new(),
    )
    .await;
    assert_eq!(res.status_code(), 404);
}

#[tokio::test]
async fn missing_project_header_is_rejected() {
    let h = boot().await;

    let req = Request::new("GET", "/v1/users");
    let res = Response::new();
    h.http.run(req, res.clone()).await.unwrap();
    assert_eq!(res.status_code(), 404);
}

#[tokio::test]
async fn key_without_required_scope_is_unauthorized() {
    let h = boot_with_scopes(&["users.read"]).await;

    let mut payload = HashMap::new();
    payload.insert("userId".to_string(), json!("unique()"));
    let res = run(&h.http, "POST", "/v1/users", payload).await;
    assert_eq!(res.status_code(), 401);
}

#[tokio::test]
async fn update_user_properties_round_trip() {
    let h = boot().await;
    let user_id = create_user_id(&h.http, "props@example.com", "Props User").await;

    // status
    let res = run(
        &h.http,
        "PATCH",
        &format!("/v1/users/{user_id}/status"),
        map(&[("status", json!(false))]),
    )
    .await;
    assert_eq!(res.status_code(), 200, "{}", res.body_string());
    assert_eq!(body_json(&res)["status"], json!(false));

    // name
    let res = run(
        &h.http,
        "PATCH",
        &format!("/v1/users/{user_id}/name"),
        map(&[("name", json!("Renamed User"))]),
    )
    .await;
    assert_eq!(res.status_code(), 200, "{}", res.body_string());
    assert_eq!(body_json(&res)["name"], json!("Renamed User"));

    // email
    let res = run(
        &h.http,
        "PATCH",
        &format!("/v1/users/{user_id}/email"),
        map(&[("email", json!("renamed@example.com"))]),
    )
    .await;
    assert_eq!(res.status_code(), 200, "{}", res.body_string());
    let body = body_json(&res);
    assert_eq!(body["email"], json!("renamed@example.com"));
    assert_eq!(body["emailVerification"], json!(false));

    // phone
    let res = run(
        &h.http,
        "PATCH",
        &format!("/v1/users/{user_id}/phone"),
        map(&[("number", json!("+910000000000"))]),
    )
    .await;
    assert_eq!(res.status_code(), 200, "{}", res.body_string());
    let body = body_json(&res);
    assert_eq!(body["phone"], json!("+910000000000"));
    assert_eq!(body["phoneVerification"], json!(false));

    // password
    let res = run(
        &h.http,
        "PATCH",
        &format!("/v1/users/{user_id}/password"),
        map(&[("password", json!("newpassword99"))]),
    )
    .await;
    assert_eq!(res.status_code(), 200, "{}", res.body_string());
    let body = body_json(&res);
    assert!(body["password"].as_str().unwrap_or("").starts_with('$'));
    assert_eq!(body["hash"], json!("argon2"));

    // labels
    let res = run(
        &h.http,
        "PUT",
        &format!("/v1/users/{user_id}/labels"),
        map(&[("labels", json!(["vip", "beta"]))]),
    )
    .await;
    assert_eq!(res.status_code(), 200, "{}", res.body_string());
    assert_eq!(body_json(&res)["labels"], json!(["vip", "beta"]));

    // prefs update + get
    let res = run(
        &h.http,
        "PATCH",
        &format!("/v1/users/{user_id}/prefs"),
        map(&[("prefs", json!({"theme": "dark", "lang": "en"}))]),
    )
    .await;
    assert_eq!(res.status_code(), 200, "{}", res.body_string());
    assert_eq!(body_json(&res)["theme"], json!("dark"));

    let res = run(
        &h.http,
        "GET",
        &format!("/v1/users/{user_id}/prefs"),
        HashMap::new(),
    )
    .await;
    assert_eq!(res.status_code(), 200, "{}", res.body_string());
    assert_eq!(
        body_json(&res),
        json!({"theme": "dark", "lang": "en"})
    );

    // email verification
    let res = run(
        &h.http,
        "PATCH",
        &format!("/v1/users/{user_id}/verification"),
        map(&[("emailVerification", json!(true))]),
    )
    .await;
    assert_eq!(res.status_code(), 200, "{}", res.body_string());
    assert_eq!(body_json(&res)["emailVerification"], json!(true));

    // phone verification
    let res = run(
        &h.http,
        "PATCH",
        &format!("/v1/users/{user_id}/verification/phone"),
        map(&[("phoneVerification", json!(true))]),
    )
    .await;
    assert_eq!(res.status_code(), 200, "{}", res.body_string());
    assert_eq!(body_json(&res)["phoneVerification"], json!(true));

    // impersonator
    let res = run(
        &h.http,
        "PATCH",
        &format!("/v1/users/{user_id}/impersonator"),
        map(&[("impersonator", json!(true))]),
    )
    .await;
    assert_eq!(res.status_code(), 200, "{}", res.body_string());
    assert_eq!(body_json(&res)["impersonator"], json!(true));
}

#[tokio::test]
async fn create_bcrypt_user_and_get() {
    let h = boot().await;
    let password = "$2a$15$xX/myGbFU.ZSKHSi6EHdBOySTdYm8QxBLXmOPHrYMwV0mHRBBSBOq";

    let res = run(
        &h.http,
        "POST",
        "/v1/users/bcrypt",
        map(&[
            ("userId", json!("bcrypt-user")),
            ("email", json!("bcrypt@example.com")),
            ("password", json!(password)),
            ("name", json!("Bcrypt User")),
        ]),
    )
    .await;
    assert_eq!(res.status_code(), 201, "{}", res.body_string());
    let created = body_json(&res);
    assert_eq!(created["$id"], json!("bcrypt-user"));
    assert_eq!(created["email"], json!("bcrypt@example.com"));
    assert_eq!(created["password"], json!(password));
    assert_eq!(created["hash"], json!("bcrypt"));

    let res = run(
        &h.http,
        "GET",
        "/v1/users/bcrypt-user",
        HashMap::new(),
    )
    .await;
    assert_eq!(res.status_code(), 200, "{}", res.body_string());
    let fetched = body_json(&res);
    assert_eq!(fetched["hash"], json!("bcrypt"));
    assert_eq!(fetched["password"], json!(password));
}

#[tokio::test]
async fn create_md5_user_and_get() {
    let h = boot().await;
    let password = "144fa7eaa4904e8ee120651997f70dcc";

    let res = run(
        &h.http,
        "POST",
        "/v1/users/md5",
        map(&[
            ("userId", json!("md5-user")),
            ("email", json!("md5@example.com")),
            ("password", json!(password)),
            ("name", json!("MD5 User")),
        ]),
    )
    .await;
    assert_eq!(res.status_code(), 201, "{}", res.body_string());
    let created = body_json(&res);
    assert_eq!(created["password"], json!(password));
    assert_eq!(created["hash"], json!("md5"));

    let res = run(&h.http, "GET", "/v1/users/md5-user", HashMap::new()).await;
    assert_eq!(res.status_code(), 200);
    assert_eq!(body_json(&res)["hash"], json!("md5"));
}

#[tokio::test]
async fn sessions_token_and_jwt_round_trip() {
    let h = boot().await;
    let user_id = create_user_id(&h.http, "session@example.com", "Session User").await;

    // create session
    let res = run(
        &h.http,
        "POST",
        &format!("/v1/users/{user_id}/sessions"),
        HashMap::new(),
    )
    .await;
    assert_eq!(res.status_code(), 201, "{}", res.body_string());
    let session = body_json(&res);
    let session_id = session["$id"].as_str().expect("session $id").to_string();
    assert_eq!(session["userId"], json!(user_id));
    assert_eq!(session["provider"], json!("server"));
    assert!(session["secret"].as_str().unwrap_or("").len() > 8);

    // list sessions
    let res = run(
        &h.http,
        "GET",
        &format!("/v1/users/{user_id}/sessions"),
        HashMap::new(),
    )
    .await;
    assert_eq!(res.status_code(), 200, "{}", res.body_string());
    let listed = body_json(&res);
    assert_eq!(listed["total"], json!(1));
    assert_eq!(listed["sessions"][0]["$id"], json!(session_id));

    // create second session, then delete one
    let res = run(
        &h.http,
        "POST",
        &format!("/v1/users/{user_id}/sessions"),
        HashMap::new(),
    )
    .await;
    assert_eq!(res.status_code(), 201, "{}", res.body_string());
    let session2_id = body_json(&res)["$id"].as_str().unwrap().to_string();

    let res = run(
        &h.http,
        "DELETE",
        &format!("/v1/users/{user_id}/sessions/{session_id}"),
        HashMap::new(),
    )
    .await;
    assert_eq!(res.status_code(), 204, "{}", res.body_string());

    let res = run(
        &h.http,
        "GET",
        &format!("/v1/users/{user_id}/sessions"),
        HashMap::new(),
    )
    .await;
    assert_eq!(res.status_code(), 200);
    let listed = body_json(&res);
    assert_eq!(listed["total"], json!(1));
    assert_eq!(listed["sessions"][0]["$id"], json!(session2_id));

    // create token
    let res = run(
        &h.http,
        "POST",
        &format!("/v1/users/{user_id}/tokens"),
        map(&[("length", json!(8)), ("expire", json!(900))]),
    )
    .await;
    assert_eq!(res.status_code(), 201, "{}", res.body_string());
    let token = body_json(&res);
    assert_eq!(token["userId"], json!(user_id));
    assert!(!token["expire"].as_str().unwrap_or("").is_empty());
    assert_eq!(
        token["secret"].as_str().unwrap_or("").len(),
        8,
        "token secret length"
    );

    // create jwt (uses remaining session)
    let res = run(
        &h.http,
        "POST",
        &format!("/v1/users/{user_id}/jwts"),
        map(&[("sessionId", json!("recent")), ("duration", json!(900))]),
    )
    .await;
    assert_eq!(res.status_code(), 201, "{}", res.body_string());
    let jwt = body_json(&res)["jwt"].as_str().unwrap_or("").to_string();
    assert!(jwt.split('.').count() == 3, "jwt should be three segments");

    // delete all sessions
    let res = run(
        &h.http,
        "DELETE",
        &format!("/v1/users/{user_id}/sessions"),
        HashMap::new(),
    )
    .await;
    assert_eq!(res.status_code(), 204, "{}", res.body_string());

    let res = run(
        &h.http,
        "GET",
        &format!("/v1/users/{user_id}/sessions"),
        HashMap::new(),
    )
    .await;
    assert_eq!(res.status_code(), 200);
    assert_eq!(body_json(&res)["total"], json!(0));
}

#[tokio::test]
async fn targets_crud_round_trip() {
    let h = boot().await;
    let user_id = create_user_id(&h.http, "targets@example.com", "Target User").await;

    let res = run(
        &h.http,
        "POST",
        &format!("/v1/users/{user_id}/targets"),
        map(&[
            ("targetId", json!("target-push-1")),
            ("providerType", json!("push")),
            ("identifier", json!("device-token-abc")),
            ("providerId", json!("provider-1")),
            ("name", json!("My Phone")),
        ]),
    )
    .await;
    assert_eq!(res.status_code(), 201, "{}", res.body_string());
    let created = body_json(&res);
    assert_eq!(created["$id"], json!("target-push-1"));
    assert_eq!(created["providerType"], json!("push"));
    assert_eq!(created["identifier"], json!("device-token-abc"));
    assert_eq!(created["providerId"], json!("provider-1"));
    assert_eq!(created["name"], json!("My Phone"));
    assert_eq!(created["expired"], json!(false));

    let res = run(
        &h.http,
        "GET",
        &format!("/v1/users/{user_id}/targets/target-push-1"),
        HashMap::new(),
    )
    .await;
    assert_eq!(res.status_code(), 200, "{}", res.body_string());
    assert_eq!(body_json(&res)["identifier"], json!("device-token-abc"));

    let res = run(
        &h.http,
        "GET",
        &format!("/v1/users/{user_id}/targets"),
        HashMap::new(),
    )
    .await;
    assert_eq!(res.status_code(), 200, "{}", res.body_string());
    let listed = body_json(&res);
    assert!(
        listed["total"].as_i64().unwrap_or(0) >= 1,
        "expected at least the push target: {listed}"
    );
    assert!(
        listed["targets"]
            .as_array()
            .unwrap()
            .iter()
            .any(|t| t["$id"] == json!("target-push-1")),
        "push target missing from list: {listed}"
    );

    let res = run(
        &h.http,
        "PATCH",
        &format!("/v1/users/{user_id}/targets/target-push-1"),
        map(&[
            ("identifier", json!("device-token-xyz")),
            ("name", json!("Updated Phone")),
        ]),
    )
    .await;
    assert_eq!(res.status_code(), 200, "{}", res.body_string());
    let updated = body_json(&res);
    assert_eq!(updated["identifier"], json!("device-token-xyz"));
    assert_eq!(updated["name"], json!("Updated Phone"));
    assert_eq!(updated["expired"], json!(false));

    let res = run(
        &h.http,
        "DELETE",
        &format!("/v1/users/{user_id}/targets/target-push-1"),
        HashMap::new(),
    )
    .await;
    assert_eq!(res.status_code(), 204, "{}", res.body_string());

    let res = run(
        &h.http,
        "GET",
        &format!("/v1/users/{user_id}/targets/target-push-1"),
        HashMap::new(),
    )
    .await;
    assert_eq!(res.status_code(), 404);
}

#[tokio::test]
async fn list_identities_and_memberships_empty() {
    let h = boot().await;
    let user_id = create_user_id(&h.http, "lists@example.com", "Lists User").await;

    let res = run(&h.http, "GET", "/v1/users/identities", HashMap::new()).await;
    assert_eq!(res.status_code(), 200, "{}", res.body_string());
    let identities = body_json(&res);
    assert_eq!(identities["total"], json!(0));
    assert_eq!(identities["identities"], json!([]));

    let res = run(
        &h.http,
        "GET",
        &format!("/v1/users/{user_id}/memberships"),
        HashMap::new(),
    )
    .await;
    assert_eq!(res.status_code(), 200, "{}", res.body_string());
    let memberships = body_json(&res);
    assert_eq!(memberships["total"], json!(0));
    assert_eq!(memberships["memberships"], json!([]));
}

#[tokio::test]
async fn mfa_update_factors_and_recovery_codes() {
    let h = boot().await;
    let created = create_user(&h.http, "mfa@example.com", "MFA User").await;
    let user_id = created["$id"].as_str().unwrap().to_string();

    // enable MFA
    let res = run(
        &h.http,
        "PATCH",
        &format!("/v1/users/{user_id}/mfa"),
        map(&[("mfa", json!(true))]),
    )
    .await;
    assert_eq!(res.status_code(), 200, "{}", res.body_string());
    assert_eq!(body_json(&res)["mfa"], json!(true));

    // verify email so list factors can report email factor when enabled
    let res = run(
        &h.http,
        "PATCH",
        &format!("/v1/users/{user_id}/verification"),
        map(&[("emailVerification", json!(true))]),
    )
    .await;
    assert_eq!(res.status_code(), 200);

    let res = run(
        &h.http,
        "GET",
        &format!("/v1/users/{user_id}/mfa/factors"),
        HashMap::new(),
    )
    .await;
    assert_eq!(res.status_code(), 200, "{}", res.body_string());
    let factors = body_json(&res);
    assert_eq!(factors["totp"], json!(false));
    assert_eq!(factors["email"], json!(true));
    assert_eq!(factors["phone"], json!(false));
    assert_eq!(factors["custom"], json!(false));

    // get recovery codes before create -> not found
    let res = run(
        &h.http,
        "GET",
        &format!("/v1/users/{user_id}/mfa/recovery-codes"),
        HashMap::new(),
    )
    .await;
    assert_eq!(res.status_code(), 404, "{}", res.body_string());

    // create recovery codes
    let res = run(
        &h.http,
        "PATCH",
        &format!("/v1/users/{user_id}/mfa/recovery-codes"),
        HashMap::new(),
    )
    .await;
    assert_eq!(res.status_code(), 201, "{}", res.body_string());
    let codes = body_json(&res)["recoveryCodes"]
        .as_array()
        .cloned()
        .unwrap_or_default();
    assert_eq!(codes.len(), 6);
    assert!(codes.iter().all(|c| c.as_str().unwrap_or("").len() == 10));

    // get recovery codes
    let res = run(
        &h.http,
        "GET",
        &format!("/v1/users/{user_id}/mfa/recovery-codes"),
        HashMap::new(),
    )
    .await;
    assert_eq!(res.status_code(), 200, "{}", res.body_string());
    assert_eq!(body_json(&res)["recoveryCodes"].as_array().unwrap().len(), 6);

    // regenerate recovery codes
    let res = run(
        &h.http,
        "PUT",
        &format!("/v1/users/{user_id}/mfa/recovery-codes"),
        HashMap::new(),
    )
    .await;
    assert_eq!(res.status_code(), 200, "{}", res.body_string());
    let regenerated = body_json(&res)["recoveryCodes"]
        .as_array()
        .cloned()
        .unwrap_or_default();
    assert_eq!(regenerated.len(), 6);
    assert_ne!(Value::Array(codes), Value::Array(regenerated));

    // create again should fail (already exists) -- after regenerate they exist
    let res = run(
        &h.http,
        "PATCH",
        &format!("/v1/users/{user_id}/mfa/recovery-codes"),
        HashMap::new(),
    )
    .await;
    assert_eq!(res.status_code(), 409, "{}", res.body_string());
}

#[tokio::test]
async fn delete_mfa_authenticator_without_totp_is_not_found() {
    let h = boot().await;
    let user_id = create_user_id(&h.http, "nototp@example.com", "No TOTP").await;

    let res = run(
        &h.http,
        "DELETE",
        &format!("/v1/users/{user_id}/mfa/authenticators/totp"),
        HashMap::new(),
    )
    .await;
    assert_eq!(res.status_code(), 404, "{}", res.body_string());
}
