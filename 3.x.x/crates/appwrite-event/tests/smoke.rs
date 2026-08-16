use appwrite_event::stub;

#[test]
fn smoke() {
    let event = stub();
    assert_eq!(event.name(), "stub");
    assert!(event.payload().is_object());
}
