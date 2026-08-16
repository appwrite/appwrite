use crate::condition::Condition;
use crate::{AttributeTypes, Attributes};
use std::any::Any;
use std::fmt::Debug;

/// Action: allow the request to continue without further WAF rules.
pub const ACTION_BYPASS: &str = "bypass";
/// Action: block the request.
pub const ACTION_DENY: &str = "deny";
/// Action: present a challenge (captcha / custom / compute).
pub const ACTION_CHALLENGE: &str = "challenge";
/// Action: allow, exposing limit metadata for an external rate limiter.
pub const ACTION_RATE_LIMIT: &str = "rateLimit";
/// Action: redirect the client.
pub const ACTION_REDIRECT: &str = "redirect";

/// WAF rule (`Utopia\WAF\Rule`).
pub trait Rule: Send + Sync + Debug + Any {
    /// Action name (`bypass`, `deny`, `challenge`, `rateLimit`, `redirect`).
    fn get_action(&self) -> &'static str;

    /// Optional rule identifier.
    fn get_id(&self) -> Option<&str>;

    /// Assign a rule identifier (object-safe mutator).
    fn set_id_mut(&mut self, id: String);

    /// Conditions that must all match.
    fn get_conditions(&self) -> &[Condition];

    /// Append a condition.
    fn add_condition(&mut self, condition: Condition);

    /// Downcast helper for concrete rule types.
    fn as_any(&self) -> &dyn Any;

    /// Evaluate rule conditions against provided attributes.
    fn matches(&self, attributes: &Attributes, types: &AttributeTypes) -> bool {
        self.get_conditions()
            .iter()
            .all(|condition| condition.matches_with(attributes, types))
    }
}

impl dyn Rule {
    /// Downcast to a concrete rule type.
    pub fn downcast_ref<T: Rule + 'static>(&self) -> Option<&T> {
        self.as_any().downcast_ref()
    }
}

/// Shared identity + conditions for concrete rule types.
#[derive(Debug, Clone, Default)]
pub(crate) struct RuleInner {
    pub(crate) id: Option<String>,
    pub(crate) conditions: Vec<Condition>,
}

impl RuleInner {
    pub(crate) fn new(conditions: impl IntoIterator<Item = Condition>) -> Self {
        Self {
            id: None,
            conditions: conditions.into_iter().collect(),
        }
    }
}

/// Map a matched rule's action to allow/deny, mirroring PHP `Firewall::applyRule`.
///
/// `bypass` and `rateLimit` allow (`true`). `deny`, `challenge`, `redirect`,
/// and unknown actions block (`false`).
pub(crate) fn apply_rule(rule: &dyn Rule) -> bool {
    matches!(rule.get_action(), ACTION_BYPASS | ACTION_RATE_LIMIT)
}
