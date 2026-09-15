# utopia-test-wiremock

Test-only helper for the **WireMock 3.12.1** container in
[`docker-compose.test.yml`](../../docker-compose.test.yml).

```bash
docker compose -f docker-compose.test.yml up -d wiremock
export WIREMOCK_URL=http://127.0.0.1:8089   # default
```

CI starts the same image as a job service. There is no in-process or JAR fallback.

## Usage

```rust
use utopia_test_wiremock::{method, path, Mock, MockServer, ResponseTemplate};

#[tokio::test]
async fn example() {
    let server = MockServer::start().await;
    Mock::given(method("GET"))
        .and(path("/ping"))
        .respond_with(ResponseTemplate::new(200).set_body_string("pong"))
        .mount(&server)
        .await;
    // point adapters at server.uri()
}
```

Dynamic handlers (`Respond`) are a local HTTP backend that WireMock proxies to.
When WireMock runs in Docker, set `WIREMOCK_CALLBACK_HOST=host.docker.internal`.
