//! Validators made of other validators.

use std::borrow::Cow;

use crate::{Error, Input, Type, Validator, Verdict, is_valid_via_validate};

/// The rules of a composite validator.
pub type Rules<'a> = Vec<Box<dyn Validator + 'a>>;

/// The verdict of a composite that `rules[index]` decided.
fn decided_by(index: usize, rule: &dyn Validator, valid: bool, verdict: Verdict) -> Verdict {
    let description = verdict.description.unwrap_or_else(|| Cow::Owned(rule.description()));
    Verdict { valid, description: Some(description), decided: true, rule: Some(index) }
}

/// The verdict of a composite no rule decided: PHP describes the first rule,
/// as its validation left it.
fn undecided(valid: bool, first: Option<Cow<'static, str>>) -> Verdict {
    Verdict { valid, description: first, decided: false, rule: None }
}

/// The description of the first rule (`$this->validators[0]->getDescription()`).
/// PHP throws `Error` for an empty list ([`try_first`]); this describes it as empty.
fn first(rules: &Rules<'_>) -> String {
    rules.first().map(|r| r.description()).unwrap_or_default()
}

fn try_first(rules: &Rules<'_>) -> Result<String, Error> {
    match rules.first() {
        Some(rule) => rule.try_description(),
        None => Err(Error::Engine("Call to a member function getDescription() on null".into())),
    }
}

macro_rules! composite {
    ($name:ident, $doc:literal, $php:literal) => {
        #[doc = $doc]
        #[doc = ""]
        #[doc = concat!("PHP: `Utopia\\Validator\\", $php, "`.")]
        pub struct $name<'a> {
            rules: Rules<'a>,
            kind: Type,
        }

        impl<'a> $name<'a> {
            /// A composite of `rules`, expecting [`Type::Mixed`].
            pub fn new(rules: Rules<'a>) -> Self {
                Self { rules, kind: Type::Mixed }
            }

            /// A composite of `rules`, expecting values of type `kind`.
            pub fn with_type(rules: Rules<'a>, kind: Type) -> Self {
                Self { rules, kind }
            }

            /// The rules, in order.
            ///
            /// PHP: `getValidators()`.
            pub fn validators(&self) -> &[Box<dyn Validator + 'a>] {
                &self.rules
            }
        }
    };
}

composite!(AllOf, "Passes when every rule passes; the first failing rule describes the failure.", "AllOf");
composite!(AnyOf, "Passes when one rule passes; the last rule tried describes the result.", "AnyOf");
composite!(NoneOf, "Passes when no rule passes; the first passing rule describes the failure.", "NoneOf");

impl Validator for AllOf<'_> {
    /// The description of the first rule; empty without rules.
    fn description(&self) -> String {
        first(&self.rules)
    }

    /// The description of the first rule, or [`Error::Engine`] without rules.
    fn try_description(&self) -> Result<String, Error> {
        try_first(&self.rules)
    }

    is_valid_via_validate!();

    /// Validates against each rule in turn and stops at the first that fails,
    /// which decides the description. When every rule passes, nothing is decided.
    fn validate(&self, value: Input<'_>) -> Result<Verdict, Error> {
        let mut first = None;
        for (i, rule) in self.rules.iter().enumerate() {
            let v = rule.validate(value)?;
            if !v.valid {
                return Ok(decided_by(i, rule.as_ref(), false, v));
            }
            if i == 0 {
                first = v.description;
            }
        }
        Ok(undecided(true, first))
    }

    /// Always `true`: the validator expects an array.
    fn is_array(&self) -> bool {
        true
    }

    /// The type given to `with_type`; [`Type::Mixed`] otherwise.
    fn kind(&self) -> Type {
        self.kind
    }
}

impl Validator for AnyOf<'_> {
    /// The description of the first rule; empty without rules.
    fn description(&self) -> String {
        first(&self.rules)
    }

    /// The description of the first rule, or [`Error::Engine`] without rules.
    fn try_description(&self) -> Result<String, Error> {
        try_first(&self.rules)
    }

    is_valid_via_validate!();

    /// Validates against each rule in turn and stops at the first that passes.
    /// Every rule tried decides the description, so the last one tried describes
    /// the result; without rules the value is invalid.
    fn validate(&self, value: Input<'_>) -> Result<Verdict, Error> {
        // Every rule tried decides; the last one tried stays.
        let mut last = Verdict::INVALID;
        for (i, rule) in self.rules.iter().enumerate() {
            let v = rule.validate(value)?;
            let valid = v.valid;
            last = decided_by(i, rule.as_ref(), valid, v);
            if valid {
                break;
            }
        }
        Ok(last)
    }

    /// Always `true`: the validator expects an array.
    fn is_array(&self) -> bool {
        true
    }

    /// The type given to `with_type`; [`Type::Mixed`] otherwise.
    fn kind(&self) -> Type {
        self.kind
    }
}

impl Validator for NoneOf<'_> {
    /// The description of the first rule; empty without rules.
    fn description(&self) -> String {
        first(&self.rules)
    }

    /// The description of the first rule, or [`Error::Engine`] without rules.
    fn try_description(&self) -> Result<String, Error> {
        try_first(&self.rules)
    }

    is_valid_via_validate!();

    /// Validates against each rule in turn and stops at the first that passes,
    /// which makes the value invalid and decides the description. When no rule
    /// passes, the value is valid and nothing is decided.
    fn validate(&self, value: Input<'_>) -> Result<Verdict, Error> {
        let mut first = None;
        for (i, rule) in self.rules.iter().enumerate() {
            let v = rule.validate(value)?;
            if v.valid {
                return Ok(decided_by(i, rule.as_ref(), false, v));
            }
            if i == 0 {
                first = v.description;
            }
        }
        Ok(undecided(true, first))
    }

    /// Always `true`: the validator expects an array.
    fn is_array(&self) -> bool {
        true
    }

    /// The type given to `with_type`; [`Type::Mixed`] otherwise.
    fn kind(&self) -> Type {
        self.kind
    }
}

/// Passes when every rule passes; the description lists every rule's,
/// numbered.
///
/// PHP: `Utopia\Validator\Multiple`.
pub struct Multiple<'a> {
    rules: Rules<'a>,
    kind: Type,
}

impl<'a> Multiple<'a> {
    /// A composite of `rules`, expecting [`Type::Mixed`].
    pub fn new(rules: Rules<'a>) -> Self {
        Self { rules, kind: Type::Mixed }
    }

    /// A composite of `rules`, expecting values of type `kind`.
    pub fn with_type(rules: Rules<'a>, kind: Type) -> Self {
        Self { rules, kind }
    }

    /// Adds a rule after the others.
    ///
    /// PHP: `addRule()`.
    pub fn add_rule(&mut self, rule: Box<dyn Validator + 'a>) -> &mut Self {
        self.rules.push(rule);
        self
    }

    fn describe<'d>(&self, descriptions: impl Iterator<Item = Cow<'d, str>>) -> String {
        let mut out = String::new();
        for (i, d) in descriptions.enumerate() {
            out.push_str(&format!("{}. {} \n", i + 1, d));
        }
        out
    }
}

impl Validator for Multiple<'_> {
    /// Every rule's description, numbered, one per line.
    fn description(&self) -> String {
        self.describe(self.rules.iter().map(|r| Cow::Owned(r.description())))
    }

    /// Every rule's description, numbered, or the first error a rule returns describing itself.
    fn try_description(&self) -> Result<String, Error> {
        let descriptions = self.rules.iter().map(|r| r.try_description()).collect::<Result<Vec<_>, _>>()?;
        Ok(self.describe(descriptions.into_iter().map(Cow::Owned)))
    }

    is_valid_via_validate!();

    /// Validates against each rule in turn and stops at the first that fails. The
    /// description lists every rule's, each as its own validation left it.
    fn validate(&self, value: Input<'_>) -> Result<Verdict, Error> {
        // Each rule keeps its own decision; the description lists them all.
        let mut described: Vec<Option<Cow<'static, str>>> = Vec::new();
        let mut valid = true;
        let mut decided = false;
        for (i, rule) in self.rules.iter().enumerate() {
            let v = rule.validate(value)?;
            decided |= v.decided;
            if let Some(d) = v.description {
                described.resize(i + 1, None);
                described[i] = Some(d);
            }
            if !v.valid {
                valid = false;
                break;
            }
        }
        if described.is_empty() {
            return Ok(Verdict { decided, ..Verdict::of(valid) });
        }
        let descriptions = self.rules.iter().enumerate().map(|(i, r)| match described.get(i) {
            Some(Some(d)) => Cow::Borrowed(d.as_ref()),
            _ => Cow::Owned(r.description()),
        });
        Ok(Verdict { valid, description: Some(Cow::Owned(self.describe(descriptions))), decided, rule: None })
    }

    /// Always `true`: the validator expects an array.
    fn is_array(&self) -> bool {
        true
    }

    /// The type given to `with_type`; [`Type::Mixed`] otherwise.
    fn kind(&self) -> Type {
        self.kind
    }
}
