/// The values of one header: most headers have one, kept without a list.
#[derive(Debug, Clone)]
pub(super) enum Values {
    One(String),
    Many(Vec<String>),
}

impl Values {
    pub(super) fn as_slice(&self) -> &[String] {
        match self {
            Values::One(value) => std::slice::from_ref(value),
            Values::Many(values) => values,
        }
    }

    pub(super) fn push(&mut self, value: String) {
        match self {
            Values::One(first) => *self = Values::Many(vec![std::mem::take(first), value]),
            Values::Many(values) => values.push(value),
        }
    }
}
