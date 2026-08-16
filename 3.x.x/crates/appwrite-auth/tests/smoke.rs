use appwrite_auth::stub;

#[test]
fn smoke() {
    assert!(stub().ensure_ready().is_ok());
}
