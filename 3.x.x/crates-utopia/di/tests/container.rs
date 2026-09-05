use utopia_di::{Container, ContainerError, Resource};

#[test]
fn set_and_get_simple_value() {
    let di = Container::new();
    di.set("age", || Ok(Resource::i64(25)));
    assert_eq!(di.get_as::<i64>("age").unwrap(), 25);
}

#[test]
fn factory_with_dependencies() {
    let di = Container::new();
    di.set("age", || Ok(Resource::i64(25)));
    di.set_with_deps("john", &["age"], |deps| {
        let age = deps[0].get_as::<i64>("age")?;
        Ok(Resource::string(format!("John Doe is {age} years old.")))
    });
    assert_eq!(
        di.get_as::<String>("john").unwrap(),
        "John Doe is 25 years old."
    );
}

#[test]
fn caches_resolved_values() {
    let di = Container::new();
    let counter = std::sync::Arc::new(std::sync::atomic::AtomicUsize::new(0));
    let c = counter.clone();
    di.set("request_id", move || {
        let n = c.fetch_add(1, std::sync::atomic::Ordering::SeqCst) + 1;
        Ok(Resource::string(format!("request-{n}")))
    });
    assert_eq!(di.get_as::<String>("request_id").unwrap(), "request-1");
    assert_eq!(di.get_as::<String>("request_id").unwrap(), "request-1");
    assert_eq!(counter.load(std::sync::atomic::Ordering::SeqCst), 1);
}

#[test]
fn child_falls_through_to_parent_cache() {
    let parent = Container::new();
    let counter = std::sync::Arc::new(std::sync::atomic::AtomicUsize::new(0));
    let c = counter.clone();
    parent.set("request_id", move || {
        let n = c.fetch_add(1, std::sync::atomic::Ordering::SeqCst) + 1;
        Ok(Resource::string(format!("request-{n}")))
    });
    assert_eq!(parent.get_as::<String>("request_id").unwrap(), "request-1");

    let child = Container::child(&parent);
    assert_eq!(child.get_as::<String>("request_id").unwrap(), "request-1");
    assert_eq!(counter.load(std::sync::atomic::Ordering::SeqCst), 1);
}

#[test]
fn child_local_override_does_not_mutate_parent() {
    let parent = Container::new();
    let counter = std::sync::Arc::new(std::sync::atomic::AtomicUsize::new(0));
    let c = counter.clone();
    parent.set("request_id", move || {
        let n = c.fetch_add(1, std::sync::atomic::Ordering::SeqCst) + 1;
        Ok(Resource::string(format!("request-{n}")))
    });
    assert_eq!(parent.get_as::<String>("request_id").unwrap(), "request-1");

    let child = Container::child(&parent);
    let c2 = counter.clone();
    child.set("request_id", move || {
        let n = c2.fetch_add(1, std::sync::atomic::Ordering::SeqCst) + 1;
        Ok(Resource::string(format!("request-{n}")))
    });
    assert_eq!(child.get_as::<String>("request_id").unwrap(), "request-2");
    assert_eq!(parent.get_as::<String>("request_id").unwrap(), "request-1");
}

#[test]
fn set_cached_binds_without_factory() {
    let parent = Container::new();
    parent.set("app", || Ok(Resource::string("app")));
    let child = Container::child(&parent);
    child.set_cached("request", Resource::string("req-1"));
    assert_eq!(child.get_as::<String>("request").unwrap(), "req-1");
    assert_eq!(child.get_as::<String>("app").unwrap(), "app");
    assert!(parent.get("request").is_err());
}

#[test]
fn missing_dependency_errors() {
    let di = Container::new();
    let err = di.get("missing").unwrap_err();
    match err {
        ContainerError::NotFound(e) => assert_eq!(e.0, "missing"),
        other => panic!("unexpected {other:?}"),
    }
}

#[test]
fn has_checks_parent() {
    let parent = Container::new();
    parent.set("x", || Ok(Resource::i64(1)));
    let child = Container::child(&parent);
    assert!(child.has("x"));
    assert!(!child.has("y"));
}

#[test]
fn override_clears_cache() {
    let di = Container::new();
    di.set("v", || Ok(Resource::i64(1)));
    assert_eq!(di.get_as::<i64>("v").unwrap(), 1);
    di.set("v", || Ok(Resource::i64(2)));
    assert_eq!(di.get_as::<i64>("v").unwrap(), 2);
}
