use appwrite_hooks::stub;

#[test]
fn smoke() {
    let hook = stub();
    assert_eq!(hook.name(), "appwrite-hooks stub");
}
