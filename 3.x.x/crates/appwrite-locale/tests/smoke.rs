use appwrite_locale::stub;

#[test]
fn smoke() {
    assert_eq!(stub().code(), "en");
}
