//! The `tokenizer` area of the query adapter (see [`super::engine`]):
//! `Tokenizer\*` tokenizers, `Token` and `TokenType`.

use utopia_query::tokenizer::{Token, TokenType, Tokenizer};

use super::engine::{Args, Object, Outcome, Ret, fault};
use crate::query_object_boilerplate;

/// A tokenizer with the PHP class it was made as.
#[derive(Clone)]
pub(crate) struct TokenizerObject {
    class: &'static str,
    tokenizer: Tokenizer,
}

/// The tokenizer `class` (relative) names.
fn tokenizer_of(class: &str) -> Option<(&'static str, Tokenizer)> {
    Some(match class {
        "Tokenizer\\Tokenizer" => ("Tokenizer\\Tokenizer", Tokenizer::new()),
        "Tokenizer\\MySQL" => ("Tokenizer\\MySQL", Tokenizer::mysql()),
        "Tokenizer\\MariaDB" => ("Tokenizer\\MariaDB", Tokenizer::mariadb()),
        "Tokenizer\\PostgreSQL" => ("Tokenizer\\PostgreSQL", Tokenizer::postgresql()),
        "Tokenizer\\SQLite" => ("Tokenizer\\SQLite", Tokenizer::sqlite()),
        "Tokenizer\\ClickHouse" => ("Tokenizer\\ClickHouse", Tokenizer::clickhouse()),
        _ => return None,
    })
}

/// `new Class(...)` for the area's classes.
pub(crate) fn construct(class: &str, a: &mut Args) -> Option<Outcome> {
    if class == "Tokenizer\\Token" {
        return Some((|| -> Outcome {
            let kind = match a.enum_case(0, "type")? {
                Some((_, case)) => TokenType::from_name(&case).ok_or_else(|| fault(format!("no TokenType::{case}")))?,
                None => return Err(fault("missing token type")),
            };
            let position = usize::try_from(a.int(2, "position")?).map_err(|_| fault("negative position"))?;
            Ok(Ret::obj(Token::new(kind, bytes_arg(a, 1, "value")?, position)))
        })());
    }
    let (class, tokenizer) = tokenizer_of(class)?;
    Some(Ok(Ret::obj(TokenizerObject { class, tokenizer })))
}

/// `Class::method(...)` for the area's classes.
pub(crate) fn call_static(class: &str, method: &str, a: &mut Args) -> Option<Outcome> {
    if method != "filter" || tokenizer_of(class).is_none() {
        return None;
    }
    Some((|| -> Outcome {
        let tokens = a
            .items(0, "tokens")?
            .into_iter()
            .map(|raw| a.object_of::<Token<'static>>(raw))
            .collect::<Result<Vec<_>, _>>()?;
        Ok(tokens_ret(Tokenizer::filter(tokens)))
    })())
}

/// `Class::CONSTANT` for the area's classes.
pub(crate) fn constant(_class: &str, _name: &str) -> Option<Ret> {
    None
}

/// `Class::Case->method(...)` for the area's enums (`TokenType` is a pure enum).
pub(crate) fn enum_call(_class: &str, _case: &str, _method: &str, _args: &mut Args) -> Option<Outcome> {
    None
}

/// A string argument as PHP's bytes (`$bytes` values included).
fn bytes_arg(a: &Args, index: usize, name: &str) -> Result<Vec<u8>, super::engine::Failure> {
    match a.value(index, name)? {
        utopia_query::Value::Str(s) => Ok(s.as_bytes().to_vec()),
        _ => Ok(a.str(index, name)?.into_bytes()),
    }
}

/// Tokens as a PHP list.
pub(crate) fn tokens_ret(tokens: Vec<Token<'static>>) -> Ret {
    Ret::List(tokens.into_iter().map(Ret::obj).collect())
}

impl Object for TokenizerObject {
    fn class(&self) -> String {
        self.class.into()
    }
    fn call(&mut self, method: &str, a: &mut Args) -> Option<Outcome> {
        match method {
            "tokenize" => Some((|| -> Outcome {
                let sql = bytes_arg(a, 0, "sql")?;
                let tokens = self.tokenizer.tokenize(&sql)?;
                Ok(tokens_ret(tokens.into_iter().map(Token::into_owned).collect()))
            })()),
            _ => None,
        }
    }
    fn parents(&self) -> &'static [&'static str] {
        match self.class {
            "Tokenizer\\Tokenizer" => &[],
            "Tokenizer\\MariaDB" => &["Tokenizer\\MySQL", "Tokenizer\\Tokenizer"],
            _ => &["Tokenizer\\Tokenizer"],
        }
    }
    query_object_boilerplate!();
}

impl Object for Token<'static> {
    fn class(&self) -> String {
        "Tokenizer\\Token".into()
    }
    fn is_value(&self) -> bool {
        true
    }
    fn props(&self) -> Vec<(&'static str, Ret)> {
        vec![
            ("type", Ret::Enum("Tokenizer\\TokenType".into(), self.kind.name())),
            ("value", Ret::str(&*self.value)),
            ("position", Ret::int(self.position as i64)),
        ]
    }
    fn call(&mut self, _method: &str, _args: &mut Args) -> Option<Outcome> {
        None
    }
    query_object_boilerplate!();
}
