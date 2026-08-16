use appwrite_exception::{stub, Exception};

#[test]
fn smoke() {
    let err = stub();
    assert!(matches!(err, Exception::General(_)));
    assert!(!err.to_string().is_empty());
}
