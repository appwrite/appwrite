use std::collections::HashMap;
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::Arc;

use serde_json::json;
use utopia_di::Container;
use utopia_http::{Http, MemoryAdapter, Request, Response};
use utopia_platform::{
    Action, ActionType, Enum, HttpMethod, Module, Platform, Service, ServiceType,
};
use utopia_validators::{Boolean, Range, Text, WhiteList};

fn hello_platform() -> Platform {
    let root = Action::new()
        .groups(["test"])
        .set_http_path("/")
        .set_http_method(HttpMethod::Get)
        .http_action(|ctx| async move {
            ctx.response.send("Hello World!")?;
            Ok(())
        });

    let init = Action::new()
        .set_type(ActionType::Init)
        .groups(["test"])
        .http_action(|ctx| async move {
            ctx.response.add_header("x-init", "init-called");
            Ok(())
        });

    let aliased = Action::new()
        .set_http_path("/aliased")
        .set_http_method(HttpMethod::Get)
        .http_alias("/alias-one")
        .http_alias("/alias-two")
        .http_action(|ctx| async move {
            ctx.response.send("Aliased!")?;
            Ok(())
        });

    let mut status_map = HashMap::new();
    status_map.insert("draft".into(), "Draft".into());
    status_map.insert("published".into(), "Published".into());

    let with_params = Action::new()
        .set_http_path("/with-params")
        .set_http_method(HttpMethod::Get)
        .param("name", json!(""), Text::new(128), "User name.", false)
        .param("age", json!(0), Range::new(0.0, 150.0), "User age.", true)
        .param("active", json!(false), Boolean::new(), "Is active.", true)
        .param_full(
            "email",
            json!(""),
            Text::new(256),
            "User email.",
            true,
            Vec::new(),
            false,
            false,
            "user@example.com",
            vec!["emailAddress".into(), "userEmail".into()],
            None,
        )
        .param_full(
            "status",
            json!("draft"),
            WhiteList::new(vec!["draft".to_string(), "published".to_string()]),
            "Status.",
            true,
            Vec::new(),
            false,
            false,
            "",
            Vec::new(),
            Some(Enum::new().with_name("ArticleStatus").with_map(status_map)),
        )
        .http_action(|ctx| async move {
            ctx.response.send("OK")?;
            Ok(())
        });

    let service = Service::http()
        .add_action("root", root)
        .add_action("initHook", init)
        .add_action("aliased", aliased)
        .add_action("withParams", with_params);

    Platform::new(Module::new()).add_service("testService", service)
}

fn with_params_action() -> Action {
    let mut status_map = HashMap::new();
    status_map.insert("draft".into(), "Draft".into());
    status_map.insert("published".into(), "Published".into());

    Action::new()
        .set_http_path("/with-params")
        .set_http_method(HttpMethod::Get)
        .param_full(
            "name",
            json!(""),
            Text::new(128),
            "User name.",
            false,
            Vec::new(),
            false,
            false,
            "John Doe",
            Vec::new(),
            None,
        )
        .param_full(
            "age",
            json!(0),
            Range::new(0.0, 150.0),
            "User age.",
            true,
            Vec::new(),
            false,
            false,
            "25",
            Vec::new(),
            None,
        )
        .param_full(
            "active",
            json!(false),
            Boolean::new(),
            "Is active.",
            true,
            Vec::new(),
            false,
            true,
            "true",
            Vec::new(),
            None,
        )
        .param_full(
            "email",
            json!(""),
            Text::new(256),
            "User email.",
            true,
            Vec::new(),
            false,
            false,
            "user@example.com",
            vec!["emailAddress".into(), "userEmail".into()],
            None,
        )
        .param_full(
            "status",
            json!("draft"),
            WhiteList::new(vec!["draft".to_string(), "published".to_string()]),
            "Status.",
            true,
            Vec::new(),
            false,
            false,
            "",
            Vec::new(),
            Some(Enum::new().with_name("ArticleStatus").with_map(status_map)),
        )
        .http_action(|ctx| async move {
            ctx.response.send("OK")?;
            Ok(())
        })
}

#[tokio::test]
async fn platform_registers_http_routes() {
    let platform = hello_platform();
    let adapter = MemoryAdapter::new(Container::new());
    let mut http = Http::new(adapter, "UTC");
    let mut platform = platform;
    platform.init_http(&mut http).unwrap();

    let response = Response::new();
    http.run(Request::new("GET", "/"), response.clone())
        .await
        .unwrap();
    assert_eq!(response.body_string(), "Hello World!");
    assert_eq!(response.header_line("x-init"), "init-called");
}

#[tokio::test]
async fn platform_http_alias_route() {
    let platform = hello_platform();
    let adapter = MemoryAdapter::new(Container::new());
    let mut http = Http::new(adapter, "UTC");
    let mut platform = platform;
    platform.init_http(&mut http).unwrap();

    let response = Response::new();
    http.run(Request::new("GET", "/alias-two"), response.clone())
        .await
        .unwrap();
    assert_eq!(response.body_string(), "Aliased!");
}

#[tokio::test]
async fn platform_module_services_by_type() {
    let module = Module::new().add_service("api", Service::http());
    assert_eq!(module.get_services_by_type(ServiceType::Http).len(), 1);
    assert_eq!(module.get_services_by_type(ServiceType::Task).len(), 0);
}

#[test]
fn action_duplicate_injection_errors() {
    let err = Action::new()
        .inject("response")
        .unwrap()
        .inject("response")
        .unwrap_err();
    assert!(matches!(
        err,
        utopia_platform::PlatformError::DuplicateInjection(_)
    ));
}

#[test]
fn sync_callback_action_registers() {
    let called = Arc::new(AtomicBool::new(false));
    let flag = called.clone();
    let action = Action::new()
        .set_http_path("/sync")
        .set_http_method(HttpMethod::Get)
        .callback(move || {
            flag.store(true, Ordering::SeqCst);
        });

    let service = Service::http().add_action("sync", action);
    let mut platform = Platform::new(Module::new()).add_service("svc", service);

    let adapter = MemoryAdapter::new(Container::new());
    let mut http = Http::new(adapter, "UTC");
    platform.init_http(&mut http).unwrap();

    let rt = tokio::runtime::Runtime::new().unwrap();
    let response = Response::new();
    rt.block_on(http.run(Request::new("GET", "/sync"), response))
        .unwrap();
    assert!(called.load(Ordering::SeqCst));
}

#[test]
fn action_param_fields_forwarded_to_route() {
    let action = with_params_action();
    let service = Service::http().add_action("withParams", action);
    let mut platform = Platform::new(Module::new()).add_service("testService", service);

    let adapter = MemoryAdapter::new(Container::new());
    let mut http = Http::new(adapter, "UTC");
    platform.init_http(&mut http).unwrap();

    let route = http
        .router()
        .match_route("GET", "/with-params")
        .expect("route /with-params should be registered")
        .route;

    let hook_meta = route.hook_meta();
    let params = hook_meta.get_params();

    for (name, param) in params {
        assert!(!param.description.is_empty() || name == "status");
        assert!(
            !param.validator.description().is_empty() || param.optional,
            "param {name} should have validator metadata"
        );
    }

    let name = params.get("name").expect("name param");
    assert_eq!(name.example, "John Doe");
    assert!(!name.deprecated);
    assert!(name.aliases.is_empty());

    let active = params.get("active").expect("active param");
    assert_eq!(active.example, "true");
    assert!(active.deprecated);

    let email = params.get("email").expect("email param");
    assert_eq!(
        email.aliases,
        vec!["emailAddress".to_string(), "userEmail".to_string()]
    );

    let status = params.get("status").expect("status param");
    let enum_meta = status.enum_meta.as_ref().expect("status enum");
    assert_eq!(enum_meta.name.as_deref(), Some("ArticleStatus"));
    assert_eq!(
        enum_meta.map.as_ref().expect("enum map"),
        &HashMap::from([
            ("draft".to_string(), "Draft".to_string()),
            ("published".to_string(), "Published".to_string()),
        ])
    );

    let action = with_params_action();
    let action_params = action.get_params();
    assert!(action_params.get("name").unwrap().enum_meta.is_none());
    let action_status = action_params
        .get("status")
        .unwrap()
        .enum_meta
        .as_ref()
        .unwrap();
    assert_eq!(action_status.name.as_deref(), Some("ArticleStatus"));
}
