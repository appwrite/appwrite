//! The shapes of the generated SDK tables ([`super::spec`]).

/// A service method of the PHP SDK.
#[derive(Debug)]
pub struct Method {
    /// The service class (`Users`, `TablesDB`, ...).
    pub service: &'static str,
    /// The method name.
    pub name: &'static str,
    /// The HTTP method.
    pub http: &'static str,
    /// The path, with `{arg}` placeholders.
    pub path: &'static str,
    /// The arguments, in order.
    pub args: &'static [Arg],
    /// The request parameters, in order.
    pub params: &'static [Param],
    /// The headers beyond `X-Appwrite-Project`, in order.
    pub headers: &'static [(&'static str, &'static str)],
    /// The model of the response.
    pub model: Option<&'static str>,
    /// For a response of several models: the key, the value it has, and
    /// the model it then is.
    pub variants: &'static [(&'static str, &'static str, &'static str)],
    /// Whether the method uploads in chunks (`createFile`).
    pub chunked: bool,
}

/// An argument of a method.
#[derive(Debug)]
pub struct Arg {
    /// The name.
    pub name: &'static str,
    /// The type: a scalar, `array`, or an enum class.
    pub ty: &'static str,
    /// Whether it is nullable.
    pub nullable: bool,
    /// The PHP default expression.
    pub default: Option<&'static str>,
}

/// A request parameter of a method.
#[derive(Debug)]
pub struct Param {
    /// The parameter key.
    pub key: &'static str,
    /// The argument it takes.
    pub arg: &'static str,
    /// Whether it is sent only when the argument is not `null`.
    pub conditional: bool,
    /// Whether the argument is an enum object (which a query string
    /// drops: `http_build_query()` finds no public property).
    pub enumerated: bool,
}

/// A response model.
#[derive(Debug)]
pub struct Model {
    /// The class (`Appwrite\Models\<name>`).
    pub name: &'static str,
    /// The keys `from()` requires.
    pub required: &'static [&'static str],
    /// The values `from()` gives absent keys (`$data += [...]`): each key
    /// and its value as JSON.
    pub defaults: &'static [(&'static str, &'static str)],
    /// The constructor's properties, in order.
    pub fields: &'static [Field],
    /// For a model with additional properties, the keys it knows.
    pub known: Option<&'static [&'static str]>,
    /// `toArray()`: each key and the property it serializes.
    pub to_array: &'static [(&'static str, &'static str)],
}

/// A property of a model.
#[derive(Debug)]
pub struct Field {
    /// The property.
    pub prop: &'static str,
    /// The response key.
    pub key: &'static str,
    /// The declared type.
    pub ty: &'static str,
    /// Whether it is nullable.
    pub nullable: bool,
    /// Whether the key may be absent (`null` then).
    pub optional: bool,
    /// Whether it holds the additional properties.
    pub additional: bool,
    /// The model or enum it is hydrated into.
    pub hydrate: Option<&'static str>,
    /// Whether `''` hydrates to `null`.
    pub allows_null: bool,
    /// Whether it is a list of `hydrate`.
    pub list: bool,
}

/// An enum of the SDK.
#[derive(Debug)]
pub struct Enum {
    /// The class.
    pub name: &'static str,
    /// The values.
    pub values: &'static [&'static str],
}
