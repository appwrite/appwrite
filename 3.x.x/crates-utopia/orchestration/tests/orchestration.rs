//! Port of `tests/Orchestration/Base.php` parse/command tests plus `DockerAPI` HTTP mocks.
//! Live Docker talks to the local daemon.

use std::collections::HashMap;

use serde_json::json;
use utopia_orchestration::prelude::*;
use utopia_orchestration::{filter_env_key, parse_command_string, parse_io_stats, restart};
use utopia_test_wiremock::{method, path, Mock, MockServer, ResponseTemplate};

/// `Base::testParseCLICommand`
#[test]
fn test_parse_cli_command() {
    let test = Orchestration::<DockerAPI>::parse_command_string(
        "sh -c 'mv /tmp/code.tar.gz /usr/local/src/code.tar.gz && tar -zxf /usr/local/src/code.tar.gz --strip 1 && rm /usr/local/src/code.tar.gz && tail -f /dev/null'",
    )
    .unwrap();
    assert_eq!(
        test,
        vec![
            "sh".to_string(),
            "-c".to_string(),
            "'mv /tmp/code.tar.gz /usr/local/src/code.tar.gz && tar -zxf /usr/local/src/code.tar.gz --strip 1 && rm /usr/local/src/code.tar.gz && tail -f /dev/null'".to_string(),
        ]
    );

    assert_eq!(
        parse_command_string("sudo apt-get update").unwrap(),
        vec!["sudo", "apt-get", "update"]
    );
    assert_eq!(parse_command_string("test").unwrap(), vec!["test"]);

    let err = parse_command_string(
        "sh -c 'mv /tmp/code.tar.gz /usr/local/src/code.tar.gz && tar -zxf /usr/local/src/code.tar.gz --strip 1 && rm /usr/local/src/code.tar.gz && tail -f /dev/null",
    )
    .unwrap_err();
    assert_eq!(
        err.to_string(),
        "Invalid Command given, are you missing an `'` at the end?"
    );
}

#[test]
fn test_filter_env_key() {
    assert_eq!(filter_env_key("FOO-BAR.1_2"), "FOO-BAR.1_2");
    assert_eq!(filter_env_key("FOO$BAR=BAZ"), "FOOBARBAZ");
}

#[test]
fn test_parse_io_stats() {
    let stats = parse_io_stats("2.133MiB / 62.8GiB");
    assert!((stats["in"] - 2.133 * 1_048_576.0).abs() < 1.0);
    assert!((stats["out"] - 62.8 * 1_073_741_824.0).abs() < 1_000_000.0);
}

#[test]
fn test_container_network_stats_accessors() {
    let mut labels = HashMap::new();
    labels.insert("author".into(), "O'Brien".into());
    let mut container = Container::new("name", "id", "Up", labels.clone());
    assert_eq!(container.get_name(), "name");
    container.set_name("n2");
    assert_eq!(container.get_name(), "n2");
    assert_eq!(container.get_labels()["author"], "O'Brien");

    let mut network = Network::new("net", "nid", "bridge", "local");
    assert_eq!(network.get_driver(), "bridge");
    network.set_scope("swarm");
    assert_eq!(network.get_scope(), "swarm");

    let stats = Stats::new(
        "abc",
        "ctr",
        0.5,
        10.0,
        HashMap::from([("in".into(), 1.0)]),
        HashMap::from([("out".into(), 2.0)]),
        HashMap::from([("in".into(), 3.0)]),
    );
    assert_eq!(stats.get_container_id(), "abc");
    assert!((stats.get_cpu_usage() - 0.5).abs() < f64::EPSILON);
}

#[tokio::test]
async fn test_docker_api_create_and_list_network() {
    let server = MockServer::start().await;
    Mock::given(method("POST"))
        .and(path("/networks/create"))
        .respond_with(
            ResponseTemplate::new(201)
                .insert_header("content-type", "application/json")
                .set_body_string(r#"{"Id":"abc"}"#),
        )
        .mount(&server)
        .await;
    Mock::given(method("GET"))
        .and(path("/networks"))
        .respond_with(ResponseTemplate::new(200).set_body_json(json!([
            {"Name": "TestNetwork", "Id": "abc", "Driver": "bridge", "Scope": "local"}
        ])))
        .mount(&server)
        .await;
    Mock::given(method("GET"))
        .and(path("/networks/TestNetwork"))
        .respond_with(ResponseTemplate::new(200).set_body_json(json!({"Name":"TestNetwork"})))
        .mount(&server)
        .await;
    Mock::given(method("DELETE"))
        .and(path("/networks/TestNetwork"))
        .respond_with(ResponseTemplate::new(204))
        .mount(&server)
        .await;

    let api = DockerAPI::new(None, None, None).with_base_url(server.uri());
    let orch = Orchestration::new(api);
    assert!(orch.create_network("TestNetwork", false).unwrap());
    let nets = orch.list_networks().unwrap();
    assert!(nets.iter().any(|n| n.get_name() == "TestNetwork"));
    assert!(orch.network_exists("TestNetwork").unwrap());
    assert!(orch.remove_network("TestNetwork").unwrap());
}

#[tokio::test]
async fn test_docker_api_create_network_conflict() {
    let server = MockServer::start().await;
    Mock::given(method("POST"))
        .and(path("/networks/create"))
        .respond_with(ResponseTemplate::new(409).set_body_string("exists"))
        .mount(&server)
        .await;
    let api = DockerAPI::new(None, None, None).with_base_url(server.uri());
    let err = api.create_network("TestNetwork", false).unwrap_err();
    assert!(err
        .to_string()
        .contains("Network with name \"TestNetwork\" already exists"));
}

#[tokio::test]
async fn test_docker_api_pull_and_list_and_remove() {
    let server = MockServer::start().await;
    Mock::given(method("POST"))
        .and(path("/images/create"))
        .respond_with(ResponseTemplate::new(200).set_body_string("ok"))
        .mount(&server)
        .await;
    Mock::given(method("GET"))
        .and(path("/containers/json"))
        .respond_with(ResponseTemplate::new(200).set_body_json(json!([
            {
                "Names": ["/TestContainer"],
                "Id": "deadbeef",
                "Status": "Up 1 second",
                "Labels": {"author": "O'Brien"}
            }
        ])))
        .mount(&server)
        .await;
    Mock::given(method("DELETE"))
        .and(path("/containers/TestContainer"))
        .respond_with(ResponseTemplate::new(204))
        .mount(&server)
        .await;

    let api = DockerAPI::new(None, None, None).with_base_url(server.uri());
    let orch = Orchestration::new(api);
    assert!(orch.pull("appwrite/runtime-for-php:8.0").unwrap());
    let list = orch.list(HashMap::new()).unwrap();
    assert_eq!(list[0].get_name(), "TestContainer");
    assert_eq!(list[0].get_labels()["author"], "O'Brien");
    assert!(orch.remove("TestContainer", true).unwrap());
}

#[tokio::test]
async fn test_docker_api_run_missing_image() {
    let server = MockServer::start().await;
    Mock::given(method("GET"))
        .and(path("/images/missing:tag/json"))
        .respond_with(ResponseTemplate::new(404))
        .mount(&server)
        .await;
    Mock::given(method("POST"))
        .and(path("/images/create"))
        .respond_with(ResponseTemplate::new(404))
        .mount(&server)
        .await;
    let api = DockerAPI::new(None, None, None).with_base_url(server.uri());
    let err = api
        .run(
            "missing:tag",
            "x",
            &[],
            "",
            "",
            &[],
            &HashMap::new(),
            "",
            &HashMap::new(),
            "",
            false,
            "",
            restart::NO,
        )
        .unwrap_err();
    assert!(err.to_string().contains("Missing image \"missing:tag\""));
}

#[tokio::test]
async fn test_docker_api_execute_success() {
    let server = MockServer::start().await;
    Mock::given(method("POST"))
        .and(path("/containers/TestContainer/exec"))
        .respond_with(ResponseTemplate::new(201).set_body_json(json!({"Id": "exec1"})))
        .mount(&server)
        .await;
    Mock::given(method("POST"))
        .and(path("/exec/exec1/start"))
        .respond_with(
            ResponseTemplate::new(200)
                .insert_header("content-type", "application/octet-stream")
                .set_body_string("Hello World!"),
        )
        .mount(&server)
        .await;
    Mock::given(method("GET"))
        .and(path("/exec/exec1/json"))
        .respond_with(
            ResponseTemplate::new(200).set_body_json(json!({"Running": false, "ExitCode": 0})),
        )
        .mount(&server)
        .await;

    let api = DockerAPI::new(None, None, None).with_base_url(server.uri());
    let mut output = String::new();
    let mut vars = HashMap::new();
    vars.insert("test".into(), "testEnviromentVariable".into());
    assert!(api
        .execute(
            "TestContainer",
            &["php".into(), "index.php".into()],
            &mut output,
            &vars,
            -1
        )
        .unwrap());
    assert_eq!(output, "Hello World!");
}

#[tokio::test]
async fn test_docker_api_stats() {
    let server = MockServer::start().await;
    Mock::given(method("GET"))
        .and(path("/containers/abc/stats"))
        .respond_with(ResponseTemplate::new(200).set_body_json(json!({
            "id": "abc",
            "name": "/UsageStats1",
            "precpu_stats": {"cpu_usage": {"total_usage": 10}, "system_cpu_usage": 100},
            "cpu_stats": {"cpu_usage": {"total_usage": 20}, "system_cpu_usage": 200, "online_cpus": 1},
            "memory_stats": {"usage": 50, "limit": 100, "max_usage": 80},
            "networks": {"eth0": {"rx_bytes": 1, "tx_bytes": 2}},
            "blkio_stats": {"io_service_bytes_recursive": [
                {"op": "Read", "value": 3},
                {"op": "Write", "value": 4}
            ]}
        })))
        .mount(&server)
        .await;

    let api = DockerAPI::new(None, None, None).with_base_url(server.uri());
    let stats = api.get_stats(Some("abc"), HashMap::new()).unwrap();
    assert_eq!(stats.len(), 1);
    assert_eq!(stats[0].get_container_name(), "UsageStats1");
    assert!(stats[0].get_cpu_usage() >= 0.0);
}

/// Live Docker CLI/API suite from PHP `Base.php` - requires a Docker daemon.
#[test]
fn test_live_docker() {
    let api = DockerAPI::new(None, None, None);
    api.list(HashMap::new())
        .expect("Docker daemon required for live orchestration tests");
}
