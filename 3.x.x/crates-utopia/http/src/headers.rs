/// Case-insensitive multi-value header map.
///
/// Compact `Vec` store - HTTP messages typically carry few headers, so linear
/// scan beats hashing for the common case. Keys are stored lowercase.
#[derive(Debug, Clone, Default)]
pub struct HeaderMap {
    entries: Vec<(Box<str>, Vec<String>)>,
}

impl HeaderMap {
    pub fn new() -> Self {
        Self {
            entries: Vec::with_capacity(8),
        }
    }

    fn lower_stack<'a>(name: &'a str, buf: &'a mut [u8]) -> Option<&'a [u8]> {
        if name.len() > buf.len() {
            return None;
        }
        for (i, b) in name.bytes().enumerate() {
            buf[i] = b.to_ascii_lowercase();
        }
        Some(&buf[..name.len()])
    }

    fn find(&self, name: &str) -> Option<usize> {
        let mut buf = [0u8; 128];
        if let Some(needle) = Self::lower_stack(name, &mut buf) {
            return self
                .entries
                .iter()
                .position(|(k, _)| k.as_bytes() == needle);
        }
        let owned = name.to_ascii_lowercase();
        self.entries.iter().position(|(k, _)| k.as_ref() == owned)
    }

    fn make_key(name: &str) -> Box<str> {
        if name.bytes().all(|b| !b.is_ascii_uppercase()) {
            name.into()
        } else {
            name.to_ascii_lowercase().into_boxed_str()
        }
    }

    pub fn has(&self, name: &str) -> bool {
        self.find(name).is_some()
    }

    pub fn get(&self, name: &str) -> Option<&[String]> {
        self.find(name).map(|i| self.entries[i].1.as_slice())
    }

    pub fn get_line(&self, name: &str, default: &str) -> String {
        match self.get(name) {
            Some([only]) => only.clone(),
            Some(values) if !values.is_empty() => values.join(", "),
            _ => default.to_string(),
        }
    }

    pub fn set(&mut self, name: &str, value: impl Into<String>) {
        let value = value.into();
        if let Some(i) = self.find(name) {
            let slot = &mut self.entries[i].1;
            slot.clear();
            slot.push(value);
        } else {
            self.entries.push((Self::make_key(name), vec![value]));
        }
    }

    pub fn add(&mut self, name: &str, value: impl Into<String>) {
        let value = value.into();
        if let Some(i) = self.find(name) {
            self.entries[i].1.push(value);
        } else {
            self.entries.push((Self::make_key(name), vec![value]));
        }
    }

    pub fn remove(&mut self, name: &str) {
        if let Some(i) = self.find(name) {
            self.entries.swap_remove(i);
        }
    }

    pub fn iter(&self) -> impl Iterator<Item = (&str, &Vec<String>)> {
        self.entries.iter().map(|(k, v)| (k.as_ref(), v))
    }

    pub fn into_inner(self) -> std::collections::HashMap<String, Vec<String>> {
        self.entries
            .into_iter()
            .map(|(k, v)| (k.into_string(), v))
            .collect()
    }
}
