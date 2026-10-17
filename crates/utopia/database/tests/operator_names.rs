//! `Operator::extractOperators()` sets an unnamed operator's attribute to
//! its key on the operator object itself (`Operator.php` line 606), so the
//! documents an upsert returns carry their operators named.

use utopia_database::Operator;
use utopia_database::filter::{Callback, Codec};
use utopia_database::value::{Array, ArrayKey, Ext, Str, Value};

#[test]
fn unnamed_operators_take_their_key() {
    let mut data: Array = [
        (ArrayKey::Str(Str::from("numbers")), Value::from(Operator::array_append([9]))),
        (ArrayKey::Str(Str::from("flag")), Value::from(Operator::toggle().set_attribute("other").clone())),
        (ArrayKey::Str(Str::from("plain")), Value::Int(1)),
    ]
    .into_iter()
    .collect();
    Operator::name_operators(&mut data);
    let name = |key: &str| match data.get_str(key) {
        Some(Value::Ext(Ext::Operator(op))) => op.attribute().to_owned(),
        other => panic!("{other:?}"),
    };
    assert_eq!(name("numbers"), "numbers");
    assert_eq!(name("flag"), "other", "a named operator keeps its attribute");
    assert_eq!(data.get_str("plain"), Some(&Value::Int(1)));
}

/// A codec built for closures declared elsewhere carries their signature
/// (`Filter\Callback::signature()`, `<file>:<line>:<file>:<line>`).
#[test]
fn a_callback_codec_takes_the_signature_it_is_given() {
    let codec = Callback::new("wrapped", Ok, Ok).with_signature("/a.php:1:/a.php:2");
    assert_eq!(codec.signature(), "/a.php:1:/a.php:2");
}
