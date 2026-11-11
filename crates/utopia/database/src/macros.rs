//! Macros shared by the crate's modules.

/// Defines a string-backed enumeration the way PHP's backed enums behave:
/// `as_str()` is `->value`, `name()` is `->name`, `try_from_value()` is
/// `tryFrom()`, `CASES` is `cases()`.
macro_rules! backed_enum {
    (
        $(#[$meta:meta])*
        $vis:vis enum $name:ident: $php:literal {
            $( $(#[$vmeta:meta])* $variant:ident = $value:literal, )*
        }
    ) => {
        $(#[$meta])*
        #[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
        $vis enum $name {
            $( $(#[$vmeta])* $variant, )*
        }

        impl $name {
            /// Every case, in declaration order (`cases()`).
            pub const CASES: &'static [$name] = &[$($name::$variant),*];

            /// The PHP class of this enumeration.
            pub const PHP_CLASS: &'static str = $php;

            /// The backing value (`->value`).
            pub const fn as_str(self) -> &'static str {
                match self {
                    $($name::$variant => $value,)*
                }
            }

            /// The case name (`->name`).
            pub const fn name(self) -> &'static str {
                match self {
                    $($name::$variant => stringify!($variant),)*
                }
            }

            /// The case with backing value `value`, if any (`tryFrom()`).
            pub fn try_from_value(value: &str) -> Option<Self> {
                match value {
                    $($value => Some($name::$variant),)*
                    _ => None,
                }
            }

            /// The case with backing value `value` (`from()`), failing as PHP
            /// does with a `ValueError`.
            pub fn from_value(value: &str) -> $crate::Result<Self> {
                Self::try_from_value(value).ok_or_else(|| {
                    $crate::Error::new(
                        $crate::Kind::ValueError,
                        format!("\"{}\" is not a valid backing value for enum {}", value, $php),
                    )
                })
            }
        }

        impl std::fmt::Display for $name {
            /// The backing value.
            fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
                f.write_str(self.as_str())
            }
        }
    };
}

pub(crate) use backed_enum;
