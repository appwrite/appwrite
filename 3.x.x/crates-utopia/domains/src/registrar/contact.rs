use std::collections::HashMap;

/// Registrant / admin / tech / billing contact (PHP `Registrar\Contact`).
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Contact {
    pub firstname: String,
    pub lastname: String,
    pub phone: String,
    pub email: String,
    pub address1: String,
    pub address2: String,
    pub address3: String,
    pub city: String,
    pub state: String,
    pub country: String,
    pub postalcode: String,
    pub org: String,
    pub owner: Option<String>,
}

impl Contact {
    /// PHP constructor (`$owner = null`).
    #[allow(clippy::too_many_arguments)]
    pub fn new(
        firstname: impl Into<String>,
        lastname: impl Into<String>,
        phone: impl Into<String>,
        email: impl Into<String>,
        address1: impl Into<String>,
        address2: impl Into<String>,
        address3: impl Into<String>,
        city: impl Into<String>,
        state: impl Into<String>,
        country: impl Into<String>,
        postalcode: impl Into<String>,
        org: impl Into<String>,
        owner: Option<String>,
    ) -> Self {
        Self {
            firstname: firstname.into(),
            lastname: lastname.into(),
            phone: phone.into(),
            email: email.into(),
            address1: address1.into(),
            address2: address2.into(),
            address3: address3.into(),
            city: city.into(),
            state: state.into(),
            country: country.into(),
            postalcode: postalcode.into(),
            org: org.into(),
            owner,
        }
    }

    /// PHP `toArray()`.
    pub fn to_array(&self) -> HashMap<String, String> {
        let owner = self
            .owner
            .clone()
            .unwrap_or_else(|| format!("{} {}", self.firstname, self.lastname));
        let mut map = HashMap::new();
        map.insert("firstname".into(), self.firstname.clone());
        map.insert("lastname".into(), self.lastname.clone());
        map.insert("phone".into(), self.phone.clone());
        map.insert("email".into(), self.email.clone());
        map.insert("address1".into(), self.address1.clone());
        map.insert("address2".into(), self.address2.clone());
        map.insert("address3".into(), self.address3.clone());
        map.insert("city".into(), self.city.clone());
        map.insert("state".into(), self.state.clone());
        map.insert("country".into(), self.country.clone());
        map.insert("postalcode".into(), self.postalcode.clone());
        map.insert("org".into(), self.org.clone());
        map.insert("owner".into(), owner);
        map
    }
}

/// PHP `array|Contact` argument for purchase.
#[derive(Debug, Clone)]
#[allow(clippy::large_enum_variant)]
pub enum Contacts {
    /// A single contact object.
    Single(Contact),
    /// Numeric list of contacts.
    List(Vec<Contact>),
    /// Associative map (`owner` / `admin` / `tech` / `billing` / `registrant`).
    Typed(HashMap<String, Contact>),
}

impl From<Contact> for Contacts {
    fn from(value: Contact) -> Self {
        Self::Single(value)
    }
}

impl From<Vec<Contact>> for Contacts {
    fn from(value: Vec<Contact>) -> Self {
        Self::List(value)
    }
}

impl From<HashMap<String, Contact>> for Contacts {
    fn from(value: HashMap<String, Contact>) -> Self {
        Self::Typed(value)
    }
}

impl Contacts {
    pub(crate) fn len(&self) -> usize {
        match self {
            Self::Single(_) => 1,
            Self::List(list) => list.len(),
            Self::Typed(map) => map.len(),
        }
    }

    pub(crate) fn get(&self, key: &str) -> Option<&Contact> {
        match self {
            Self::Typed(map) => map.get(key),
            Self::List(list) => {
                let idx: usize = key.parse().ok()?;
                list.get(idx)
            }
            Self::Single(contact) if key == "0" => Some(contact),
            Self::Single(_) => None,
        }
    }

    pub(crate) fn first(&self) -> Option<&Contact> {
        match self {
            Self::Single(contact) => Some(contact),
            Self::List(list) => list.first(),
            Self::Typed(map) => map.values().next(),
        }
    }

    pub(crate) fn iter_pairs(&self) -> Vec<(String, Contact)> {
        match self {
            Self::Single(contact) => vec![("0".into(), contact.clone())],
            Self::List(list) => list
                .iter()
                .enumerate()
                .map(|(i, c)| (i.to_string(), c.clone()))
                .collect(),
            Self::Typed(map) => map.iter().map(|(k, v)| (k.clone(), v.clone())).collect(),
        }
    }
}
