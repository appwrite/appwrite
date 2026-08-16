use appwrite_platform::stub;

#[test]
fn smoke() {
    assert!(stub().ensure_ready().is_ok());
}
