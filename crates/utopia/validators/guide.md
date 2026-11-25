## Check a value

Each function is the body of a PHP validator's method, over a value as PHP sees it (`Input`), with the same coercions, comparisons and messages.

```rust
use utopia_validators::{Input, Integer, Number, range};

let int8 = Integer::new(8, false).unwrap();
assert!(int8.accepts(true, Input::Str(b" 127 ")));
assert!(!int8.accepts(true, Input::Float(1.0)));
assert!(range(Number::Int(1), Number::Int(10), b"integer", Input::Str(b"5")));
```
