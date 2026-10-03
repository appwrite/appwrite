use utopia_http::prelude::*;

#[test]
fn static_route_match() {
    let router = Router::new();
    let route = Route::new(vec!["GET".into()], "/hello", 1);
    let route = std::sync::Arc::new(route);
    router.add_route(route.clone()).unwrap();
    let m = router.match_route("GET", "/hello").unwrap();
    assert!(std::sync::Arc::ptr_eq(&m.route, &route));
    assert!(m.params.is_empty());
}

#[test]
fn param_route_match() {
    let router = Router::new();
    let route = std::sync::Arc::new(Route::new(vec!["GET".into()], "/users/:id", 1));
    router.add_route(route).unwrap();
    let m = router.match_route("GET", "/users/42").unwrap();
    assert_eq!(m.params.get("id").map(String::as_str), Some("42"));
}

#[test]
fn wildcard_fallback() {
    let router = Router::new();
    let wild = std::sync::Arc::new(Route::new(vec![], "", 1));
    router.set_wildcard(wild.clone());
    let m = router.match_route("GET", "/missing").unwrap();
    assert!(std::sync::Arc::ptr_eq(&m.route, &wild));
}

#[test]
fn duplicate_rejected() {
    let router = Router::new();
    let a = std::sync::Arc::new(Route::new(vec!["GET".into()], "/x", 1));
    let b = std::sync::Arc::new(Route::new(vec!["GET".into()], "/x", 2));
    router.add_route(a).unwrap();
    assert!(router.add_route(b).is_err());
}
