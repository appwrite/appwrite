use appwrite_response::stub;

#[test]
fn smoke() {
    let model = stub();
    assert_eq!(model.name(), "stub");
}
