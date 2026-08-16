use utopia_http::HeaderMap;

#[test]
fn header_map_case_insensitive() {
    let mut h = HeaderMap::new();
    h.set("Content-Type", "application/json");
    h.add("X-A", "1");
    h.add("x-a", "2");
    assert!(h.has("content-type"));
    assert_eq!(h.get_line("x-a", ""), "1, 2");
}
