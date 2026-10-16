use utopia_abuse::adapters::ReCaptcha;
use utopia_abuse::{Abuse, AbuseError, Adapter};
use utopia_test_wiremock::{
    body_string_contains, method, path, Mock, MockServer, ResponseTemplate,
};

fn runtime() -> tokio::runtime::Runtime {
    tokio::runtime::Builder::new_multi_thread()
        .worker_threads(2)
        .enable_all()
        .build()
        .expect("tokio runtime")
}

#[test]
fn human_when_success_and_score_meets_threshold() {
    let rt = runtime();
    let server = rt.block_on(MockServer::start());
    rt.block_on(async {
        Mock::given(method("POST"))
            .and(path("/recaptcha/api/siteverify"))
            .and(body_string_contains("secret="))
            .respond_with(
                ResponseTemplate::new(200)
                    .insert_header("content-type", "application/json")
                    .set_body_string(r#"{"success":true,"score":0.9}"#),
            )
            .mount(&server)
            .await;
    });

    let mut adapter = ReCaptcha::new("secret key", "token", "127.0.0.1")
        .with_siteverify_url(format!("{}/recaptcha/api/siteverify", server.uri()));
    assert!(adapter.check().unwrap());
}

#[test]
fn bot_when_score_below_threshold() {
    let rt = runtime();
    let server = rt.block_on(MockServer::start());
    rt.block_on(async {
        Mock::given(method("POST"))
            .respond_with(
                ResponseTemplate::new(200)
                    .insert_header("content-type", "application/json")
                    .set_body_string(r#"{"success":true,"score":0.1}"#),
            )
            .mount(&server)
            .await;
    });

    let adapter = ReCaptcha::new("s", "t", "1.2.3.4")
        .with_siteverify_url(format!("{}/siteverify", server.uri()));
    assert!(!adapter.check_with_score(0.5).unwrap());
}

#[test]
fn bot_when_success_false() {
    let rt = runtime();
    let server = rt.block_on(MockServer::start());
    rt.block_on(async {
        Mock::given(method("POST"))
            .respond_with(
                ResponseTemplate::new(200)
                    .insert_header("content-type", "application/json")
                    .set_body_string(r#"{"success":false,"score":1.0}"#),
            )
            .mount(&server)
            .await;
    });

    let adapter = ReCaptcha::new("s", "t", "1.2.3.4")
        .with_siteverify_url(format!("{}/siteverify", server.uri()));
    assert!(!adapter.check_with_score(0.5).unwrap());
}

#[test]
fn double_encodes_form_fields_like_php() {
    let rt = runtime();
    let server = rt.block_on(MockServer::start());
    rt.block_on(async {
        Mock::given(method("POST"))
            .and(body_string_contains("secret=sec%2Bret"))
            .respond_with(
                ResponseTemplate::new(200)
                    .insert_header("content-type", "application/json")
                    .set_body_string(r#"{"success":true,"score":1}"#),
            )
            .mount(&server)
            .await;
    });

    // PHP urlencode("sec ret") => "sec+ret", then http_build_query encodes + as %2B
    let adapter = ReCaptcha::new("sec ret", "r", "1.1.1.1").with_siteverify_url(server.uri());
    assert!(adapter.check_with_score(0.5).unwrap());
}

#[test]
fn unsupported_methods() {
    let mut adapter = ReCaptcha::new("s", "t", "ip");
    assert!(matches!(
        adapter.get_logs(None, None).unwrap_err(),
        AbuseError::MethodNotSupported
    ));
    assert!(matches!(
        adapter.cleanup(0).unwrap_err(),
        AbuseError::MethodNotSupported
    ));
    assert!(matches!(
        adapter.reset().unwrap_err(),
        AbuseError::MethodNotSupported
    ));
    let mut abuse = Abuse::new(adapter);
    assert!(matches!(
        abuse.reset().unwrap_err(),
        AbuseError::MethodNotSupported
    ));
}
