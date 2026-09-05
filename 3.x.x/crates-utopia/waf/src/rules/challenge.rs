use crate::condition::Condition;
use crate::error::InvalidArgumentError;
use crate::rule::{Rule, RuleInner, ACTION_CHALLENGE};
use std::any::Any;

/// Challenge the client (`Utopia\WAF\Rules\Challenge`).
#[derive(Debug, Clone)]
pub struct Challenge {
    inner: RuleInner,
    challenge_type: String,
}

impl Challenge {
    /// Captcha challenge (PHP `Challenge::TYPE_CAPTCHA`).
    pub const TYPE_CAPTCHA: &'static str = "captcha";
    /// Custom challenge.
    pub const TYPE_CUSTOM: &'static str = "custom";
    /// Compute / proof-of-work challenge.
    pub const TYPE_COMPUTE: &'static str = "compute";

    /// Create a captcha challenge rule.
    pub fn new(conditions: impl IntoIterator<Item = Condition>) -> Self {
        Self {
            inner: RuleInner::new(conditions),
            challenge_type: Self::TYPE_CAPTCHA.to_string(),
        }
    }

    /// Create a challenge rule with an explicit type.
    ///
    /// # Errors
    ///
    /// Returns [`InvalidArgumentError::InvalidChallengeType`] when `challenge_type`
    /// is not `captcha`, `custom`, or `compute`.
    pub fn with_type(
        conditions: impl IntoIterator<Item = Condition>,
        challenge_type: impl AsRef<str>,
    ) -> Result<Self, InvalidArgumentError> {
        let challenge_type = challenge_type.as_ref();
        if !matches!(
            challenge_type,
            Self::TYPE_CAPTCHA | Self::TYPE_CUSTOM | Self::TYPE_COMPUTE
        ) {
            return Err(InvalidArgumentError::InvalidChallengeType(
                challenge_type.to_string(),
            ));
        }
        Ok(Self {
            inner: RuleInner::new(conditions),
            challenge_type: challenge_type.to_string(),
        })
    }

    /// Challenge kind (`captcha`, `custom`, `compute`).
    pub fn get_type(&self) -> &str {
        &self.challenge_type
    }

    /// Fluent identifier setter (PHP `setId`).
    pub fn set_id(mut self, id: impl Into<String>) -> Self {
        self.inner.id = Some(id.into());
        self
    }
}

impl Default for Challenge {
    fn default() -> Self {
        Self::new(Vec::<Condition>::new())
    }
}

impl Rule for Challenge {
    fn get_action(&self) -> &'static str {
        ACTION_CHALLENGE
    }

    fn get_id(&self) -> Option<&str> {
        self.inner.id.as_deref()
    }

    fn set_id_mut(&mut self, id: String) {
        self.inner.id = Some(id);
    }

    fn get_conditions(&self) -> &[Condition] {
        &self.inner.conditions
    }

    fn add_condition(&mut self, condition: Condition) {
        self.inner.conditions.push(condition);
    }

    fn as_any(&self) -> &dyn Any {
        self
    }
}
