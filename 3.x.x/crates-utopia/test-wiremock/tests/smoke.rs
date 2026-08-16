use utopia_test_wiremock::{method, path, Mock, MockServer, ResponseTemplate};

#[tokio::test(flavor = "multi_thread")]
async fn wiremock_round_trip() {
    let server = MockServer::start().await;
    Mock::given(method("GET"))
        .and(path("/ping"))
        .respond_with(ResponseTemplate::new(200).set_body_string("pong"))
        .mount(&server)
        .await;
    let url = format!("{}/ping", server.uri());
    let body = reqwest::get(&url)
        .await
        .expect("get")
        .text()
        .await
        .expect("text");
    assert_eq!(body, "pong");
    let requests = server.received_requests().await.expect("journal");
    assert_eq!(requests.len(), 1);
    assert_eq!(requests[0].method.as_str(), "GET");
}
