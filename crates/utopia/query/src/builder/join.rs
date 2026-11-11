//! [`JoinBuilder`]: the conditions of a join built with a closure.

use crate::builder::types::{Condition, JoinOn};
use crate::error::{Error, Result};
use crate::value::{IntoValue, IntoValues};

const OPERATORS: [&str; 7] = ["=", "!=", "<", ">", "<=", ">=", "<>"];

/// `/^[a-zA-Z_][a-zA-Z0-9_.]*$/` (where `$` also matches before a final newline).
pub(crate) fn is_identifier_path(name: &str) -> bool {
    crate::hook::is_column_name(name)
}

/// The conditions of one join: column comparisons (`on`) and conditions
/// with bindings (`where`, raw SQL).
///
/// PHP: `Utopia\Query\Builder\JoinBuilder`.
#[derive(Debug, Clone, PartialEq, Default)]
pub struct JoinBuilder {
    ons: Vec<JoinOn>,
    wheres: Vec<Condition>,
}

impl JoinBuilder {
    /// No conditions.
    pub fn new() -> Self {
        JoinBuilder::default()
    }

    /// `left operator right` (columns; PHP's default operator is `=`).
    ///
    /// PHP: `on()`.
    pub fn on(&mut self, left: &str, right: &str, operator: &str) -> Result<&mut Self> {
        if !is_identifier_path(left) {
            return Err(Error::Validation(format!("Invalid column name: {left}")));
        }
        if !is_identifier_path(right) {
            return Err(Error::Validation(format!("Invalid column name: {right}")));
        }
        if !OPERATORS.contains(&operator) {
            return Err(Error::Validation(format!("Invalid join operator: {operator}")));
        }
        self.ons.push(JoinOn::new(left, operator, right));
        Ok(self)
    }

    /// A raw ON condition with its bindings.
    ///
    /// PHP: `onRaw()`.
    pub fn on_raw(&mut self, expression: impl Into<String>, bindings: impl IntoValues) -> &mut Self {
        self.wheres.push(Condition::new(expression, bindings.into_values().values().cloned().collect()));
        self
    }

    /// `column operator ?` with `value` bound.
    ///
    /// PHP: `where()`.
    pub fn where_(&mut self, column: &str, operator: &str, value: impl IntoValue) -> Result<&mut Self> {
        if !is_identifier_path(column) {
            return Err(Error::Validation(format!("Invalid column name: {column}")));
        }
        if !OPERATORS.contains(&operator) {
            return Err(Error::Validation(format!("Invalid join operator: {operator}")));
        }
        self.wheres.push(Condition::new(format!("{column} {operator} ?"), vec![value.into_value()]));
        Ok(self)
    }

    /// A raw condition with its bindings.
    ///
    /// PHP: `whereRaw()`.
    pub fn where_raw(&mut self, expression: impl Into<String>, bindings: impl IntoValues) -> &mut Self {
        self.wheres.push(Condition::new(expression, bindings.into_values().values().cloned().collect()));
        self
    }

    /// The column comparisons.
    ///
    /// PHP: `$ons`.
    pub fn ons(&self) -> &[JoinOn] {
        &self.ons
    }

    /// The conditions with bindings.
    ///
    /// PHP: `$wheres`.
    pub fn wheres(&self) -> &[Condition] {
        &self.wheres
    }
}
