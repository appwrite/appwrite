//! Ported from utopia-php/database 8.1.3 `tests/unit/IdTest.php`.

use utopia_database::id;

#[test]
fn test_custom_id() {
    assert_eq!(id::custom("test"), "test");
}

#[test]
fn test_unique_id() {
    let id = id::unique().unwrap();
    assert!(!id.is_empty());
    assert_eq!(id.len(), 20);
    assert_eq!(id::unique_padded(0).unwrap().len(), 13);
    assert_eq!(id::unique_padded(3).unwrap().len(), 16);
}
