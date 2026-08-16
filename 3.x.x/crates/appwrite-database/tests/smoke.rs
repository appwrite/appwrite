use appwrite_database::stub;

#[test]
fn smoke() {
    assert!(stub().ping().is_ok());
}
