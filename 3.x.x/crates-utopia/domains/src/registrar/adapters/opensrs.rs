use std::collections::HashMap;

use bytes::Bytes;
use http::{Method, Request};
use md5::{Digest, Md5};
use quick_xml::events::Event;
use quick_xml::Reader;
use serde_json::{json, Value};
use time::OffsetDateTime;
use utopia_client::adapter::curl;
use utopia_client::{Client, StreamingClient};

use super::namecom::{normalize_endpoint, parse_datetime};
use crate::cache::Cache;
use crate::error::DomainsError;
use crate::registrar::adapter::{Adapter, AdapterState};
use crate::registrar::{
    Contact, Contacts, NameserverUpdate, Price, Registrar, RegistrarDomain, Renewal, SuggestItem,
    SuggestQuery, TransferStatus, TransferStatusEnum, UpdateDetails,
};

/// `OpenSRS` XML registrar adapter (PHP `Adapter\OpenSRS`).
#[derive(Debug)]
pub struct OpenSrs {
    api_key: String,
    username: String,
    password: String,
    endpoint: String,
    state: AdapterState,
}

impl OpenSrs {
    /// PHP `RESPONSE_CODE_DOMAIN_AVAILABLE`.
    pub const RESPONSE_CODE_DOMAIN_AVAILABLE: i64 = 210;
    /// PHP `RESPONSE_CODE_NOTHING_TO_DO`.
    pub const RESPONSE_CODE_NOTHING_TO_DO: i64 = 220;
    /// PHP `RESPONSE_CODE_DOMAIN_PRICE_NOT_FOUND`.
    pub const RESPONSE_CODE_DOMAIN_PRICE_NOT_FOUND: i64 = 400;
    /// PHP `RESPONSE_CODE_INVALID_CONTACT`.
    pub const RESPONSE_CODE_INVALID_CONTACT: i64 = 465;
    /// PHP `RESPONSE_CODE_DOMAIN_TAKEN`.
    pub const RESPONSE_CODE_DOMAIN_TAKEN: i64 = 485;
    /// PHP `RESPONSE_CODE_DOMAIN_NOT_TRANSFERABLE`.
    pub const RESPONSE_CODE_DOMAIN_NOT_TRANSFERABLE: i64 = 487;

    /// PHP contact type constants.
    pub const CONTACT_TYPE_OWNER: &'static str = "owner";
    /// Admin contact type.
    pub const CONTACT_TYPE_ADMIN: &'static str = "admin";
    /// Tech contact type.
    pub const CONTACT_TYPE_TECH: &'static str = "tech";
    /// Billing contact type.
    pub const CONTACT_TYPE_BILLING: &'static str = "billing";

    /// PHP constructor.
    pub fn new(
        api_key: impl Into<String>,
        username: impl Into<String>,
        password: impl Into<String>,
        endpoint: impl Into<String>,
    ) -> Self {
        Self {
            api_key: api_key.into(),
            username: username.into(),
            password: password.into(),
            endpoint: normalize_endpoint(&endpoint.into()),
            state: AdapterState::default(),
        }
    }

    fn send(
        &self,
        object: &str,
        action: &str,
        attributes: &OpsVal,
        domain: Option<&str>,
    ) -> Result<String, DomainsError> {
        let xml = build_envelope(object, action, attributes, domain);
        let signature = opensrs_signature(&xml, &self.api_key);
        let client = Client::new(curl::Client::new())
            .with_timeout(self.state.timeout as f64)
            .and_then(|client| client.with_connect_timeout(self.state.connect_timeout as f64))
            .map_err(|e| {
                DomainsError::generic(format!("Failed to send request to OpenSRS: {e}"), 0)
            })?;
        let request = Request::builder()
            .method(Method::POST)
            .uri(&self.endpoint)
            .header("Content-Type", "text/xml")
            .header("X-Username", &self.username)
            .header("X-Signature", signature)
            .body(Bytes::from(xml))
            .map_err(|e| {
                DomainsError::generic(format!("Failed to send request to OpenSRS: {e}"), 0)
            })?;
        let response = client.send_request(request).map_err(|e| {
            DomainsError::generic(format!("Failed to send request to OpenSRS: {e}"), 0)
        })?;
        Ok(String::from_utf8_lossy(response.body()).into_owned())
    }

    fn sanitize_response(&self, xml: &str) -> Result<OpsNode, DomainsError> {
        let root = parse_ops(xml)?;
        let code = root
            .assoc_get("response_code")
            .and_then(OpsNode::as_text)
            .and_then(|s| s.parse::<i64>().ok())
            .unwrap_or(0);
        if code > 299 {
            let text = root
                .assoc_get("response_text")
                .and_then(OpsNode::as_text)
                .unwrap_or("")
                .to_string();
            return Err(DomainsError::generic(text, code));
        }
        Ok(root)
    }

    fn response(&self, xml: &str) -> Result<HashMap<String, String>, DomainsError> {
        let doc = self.sanitize_response(xml)?;
        let code = doc
            .assoc_get("response_code")
            .and_then(OpsNode::as_text)
            .unwrap_or("")
            .to_string();
        let id = doc
            .assoc_get("attributes")
            .and_then(|a| a.assoc_get("id"))
            .and_then(OpsNode::as_text)
            .unwrap_or("")
            .to_string();
        let domain_id = doc
            .assoc_get("attributes")
            .and_then(|a| a.assoc_get("domain_id"))
            .and_then(OpsNode::as_text)
            .unwrap_or("")
            .to_string();
        let successful = doc
            .assoc_get("is_success")
            .and_then(OpsNode::as_text)
            .unwrap_or("")
            == "1";
        Ok(HashMap::from([
            ("code".into(), code),
            ("id".into(), id),
            ("domainId".into(), domain_id),
            (
                "successful".into(),
                if successful { "1" } else { "0" }.into(),
            ),
        ]))
    }

    #[allow(clippy::too_many_arguments)]
    fn register(
        &self,
        domain: &str,
        reg_type: &str,
        contacts: &OpsVal,
        nameservers: &[String],
        period_years: i64,
        auth_code: Option<&str>,
        purchase_price: Option<f64>,
        autorenew_enabled: bool,
    ) -> Result<String, DomainsError> {
        let has_ns = i64::from(!nameservers.is_empty());
        let mut attrs = vec![
            ("domain".into(), OpsVal::text(domain)),
            ("periodYears".into(), OpsVal::text(period_years.to_string())),
            ("contact_set".into(), contacts.clone()),
            ("custom_tech_contact".into(), OpsVal::text("0")),
            (
                "custom_nameservers".into(),
                OpsVal::text(has_ns.to_string()),
            ),
            ("reg_username".into(), OpsVal::text(&self.username)),
            ("reg_password".into(), OpsVal::text(&self.password)),
            ("reg_type".into(), OpsVal::text(reg_type)),
            ("handle".into(), OpsVal::text("process")),
            ("f_whois_privacy".into(), OpsVal::text("1")),
            (
                "auto_renew".into(),
                OpsVal::text(if autorenew_enabled { "1" } else { "0" }),
            ),
        ];
        if let Some(auth) = auth_code {
            if !auth.is_empty() {
                attrs.push(("auth_info".into(), OpsVal::text(auth)));
            }
        }
        if has_ns == 1 {
            attrs.push((
                "nameserver_list".into(),
                OpsVal::List(nameservers.iter().map(OpsVal::text).collect()),
            ));
        }
        if let Some(price) = purchase_price {
            attrs.push((
                "premium_price_to_display".into(),
                OpsVal::text(price.to_string()),
            ));
        }
        self.send("DOMAIN", "SW_REGISTER", &OpsVal::Map(attrs), None)
    }

    fn sanitize_contacts(&self, contacts: &Contacts) -> OpsVal {
        if contacts.len() == 1 {
            let Some(contact) = contacts.first() else {
                return OpsVal::Map(Vec::new());
            };
            let data = OpsVal::Map(contact_items(contact));
            return OpsVal::Map(vec![
                (Self::CONTACT_TYPE_OWNER.into(), data.clone()),
                (Self::CONTACT_TYPE_ADMIN.into(), data.clone()),
                (Self::CONTACT_TYPE_TECH.into(), data.clone()),
                (Self::CONTACT_TYPE_BILLING.into(), data),
            ]);
        }
        let mut result = Vec::new();
        for (key, contact) in contacts.iter_pairs() {
            result.push((key, OpsVal::Map(contact_items(&contact))));
        }
        OpsVal::Map(result)
    }
}

fn map_transfer_error(e: DomainsError) -> Result<String, DomainsError> {
    if e.code() == OpenSrs::RESPONSE_CODE_DOMAIN_NOT_TRANSFERABLE {
        let message = e.message();
        let parts: Vec<&str> = message.split('\n').collect();
        let reason = parts.get(1).copied().unwrap_or(parts[0]);
        Err(DomainsError::domain_not_transferable(
            format!("Domain is not transferable: {reason}"),
            e.code(),
        ))
    } else if e.code() == OpenSrs::RESPONSE_CODE_DOMAIN_TAKEN {
        Err(DomainsError::domain_taken(
            "Domain is already in this account",
            e.code(),
        ))
    } else {
        Err(DomainsError::generic(
            format!("Failed to transfer domain: {}", e.message()),
            e.code(),
        ))
    }
}

fn contact_items(contact: &Contact) -> Vec<(String, OpsVal)> {
    let data = contact.to_array();
    let mut items = Vec::new();
    for key in [
        "firstname",
        "lastname",
        "phone",
        "email",
        "address1",
        "address2",
        "address3",
        "city",
        "state",
        "country",
        "postalcode",
        "org",
        "owner",
    ] {
        items.push((
            key.to_string(),
            OpsVal::text(data.get(key).cloned().unwrap_or_default()),
        ));
    }
    items
}

fn opensrs_signature(xml: &str, api_key: &str) -> String {
    let first = md5_hex(&format!("{xml}{api_key}"));
    md5_hex(&format!("{first}{api_key}"))
}

fn md5_hex(input: &str) -> String {
    let mut hasher = Md5::new();
    hasher.update(input.as_bytes());
    format!("{:x}", hasher.finalize())
}

fn validate_contact(
    contact: &HashMap<String, String>,
) -> Result<Vec<(String, String)>, DomainsError> {
    let required = [
        "firstname",
        "lastname",
        "email",
        "phone",
        "address1",
        "city",
        "state",
        "postalcode",
        "country",
        "owner",
        "org",
    ];
    let mut result = Vec::new();
    for key in required {
        let Some(value) = contact.get(key) else {
            return Err(DomainsError::invalid_contact(
                format!("Contact is missing required field: {key}"),
                0,
            ));
        };
        let filtered = match key {
            "firstname" => "first_name",
            "lastname" => "last_name",
            "org" => "org_name",
            "postalcode" => "postal_code",
            other => other,
        };
        result.push((filtered.to_string(), value.clone()));
    }
    Ok(result)
}

#[derive(Debug, Clone)]
enum OpsVal {
    Text(String),
    List(Vec<OpsVal>),
    Map(Vec<(String, OpsVal)>),
}

impl OpsVal {
    fn text(value: impl Into<String>) -> Self {
        Self::Text(value.into())
    }
}

fn build_envelope(object: &str, action: &str, attributes: &OpsVal, domain: Option<&str>) -> String {
    let mut result = vec![
        r#"<?xml version="1.0" encoding="UTF-8" standalone="no"?>"#.to_string(),
        "<!DOCTYPE OPS_envelope SYSTEM 'ops.dtd'>".into(),
        "<OPS_envelope>".into(),
        "<header>".into(),
        "<version>0.9</version>".into(),
        "</header>".into(),
        "<body>".into(),
        "<data_block>".into(),
        "<dt_assoc>".into(),
        create_item("protocol", "XCP"),
        create_item("object", object),
        create_item("action", action),
    ];
    if let Some(domain) = domain {
        result.push(create_item("domain", domain));
    }
    result.push("<item key=\"attributes\">".into());
    result.push("<dt_assoc>".into());
    if let OpsVal::Map(entries) = attributes {
        for (key, value) in entries {
            match key.as_str() {
                "contact_set" => result.push(create_contact_set(value)),
                "nameserver_list" => result.push(create_nameserver_list(value)),
                "assign_ns" | "add_ns" | "remove_ns" => result.push(create_ns_assign(key, value)),
                "service_override" => result.push(create_service_override(value)),
                _ => result.push(encode_value(key, value)),
            }
        }
    }
    result.extend([
        "</dt_assoc>".into(),
        "</item>".into(),
        "</dt_assoc>".into(),
        "</data_block>".into(),
        "</body>".into(),
        "</OPS_envelope>".into(),
    ]);
    result.join("\n")
}

fn create_item(key: &str, value: &str) -> String {
    format!("<item key='{key}'>{value}</item>")
}

fn encode_value(key: &str, value: &OpsVal) -> String {
    match value {
        OpsVal::Text(text) => create_item(key, text),
        OpsVal::List(items) => create_array(key, items),
        OpsVal::Map(entries) => create_assoc(key, entries),
    }
}

fn create_array(key: &str, items: &[OpsVal]) -> String {
    let mut result = vec![format!("<item key=\"{key}\">"), "<dt_array>".into()];
    for (i, item) in items.iter().enumerate() {
        result.push(encode_value(&i.to_string(), item));
    }
    result.push("</dt_array>".into());
    result.push("</item>".into());
    result.join("\n")
}

fn create_assoc(key: &str, entries: &[(String, OpsVal)]) -> String {
    let mut result = vec![format!("<item key=\"{key}\">"), "<dt_assoc>".into()];
    for (item_key, item_value) in entries {
        result.push(encode_value(item_key, item_value));
    }
    result.push("</dt_assoc>".into());
    result.push("</item>".into());
    result.join("\n")
}

fn create_service_override(value: &OpsVal) -> String {
    let OpsVal::Map(entries) = value else {
        return encode_value("service_override", value);
    };
    let mut result = vec![
        "<item key=\"service_override\">".into(),
        "<dt_assoc>".into(),
    ];
    for (name, config) in entries {
        if let OpsVal::Map(cfg) = config {
            result.push(create_assoc(name, cfg));
        } else {
            result.push(encode_value(name, config));
        }
    }
    result.push("</dt_assoc>".into());
    result.push("</item>".into());
    result.join("\n")
}

fn create_contact_set(value: &OpsVal) -> String {
    let OpsVal::Map(entries) = value else {
        return encode_value("contact_set", value);
    };
    let mut result = vec!["<item key=\"contact_set\">".into(), "<dt_assoc>".into()];
    for (ctype, contact) in entries {
        if let OpsVal::Map(fields) = contact {
            let mut map = HashMap::new();
            for (k, v) in fields {
                if let OpsVal::Text(t) = v {
                    map.insert(k.clone(), t.clone());
                }
            }
            match validate_contact(&map) {
                Ok(validated) => {
                    result.push(format!("<item key='{ctype}'>"));
                    result.push("<dt_assoc>".into());
                    for (k, v) in validated {
                        result.push(create_item(&k, &v));
                    }
                    result.push("</dt_assoc>".into());
                    result.push("</item>".into());
                }
                Err(_) => {
                    result.push(create_assoc(ctype, fields));
                }
            }
        }
    }
    result.push("</dt_assoc>".into());
    result.push("</item>".into());
    result.join("\n")
}

fn create_nameserver_list(value: &OpsVal) -> String {
    let names: Vec<String> = match value {
        OpsVal::List(items) => items
            .iter()
            .filter_map(|v| match v {
                OpsVal::Text(t) => Some(t.clone()),
                _ => None,
            })
            .collect(),
        _ => Vec::new(),
    };
    let mut result = vec!["<item key=\"nameserver_list\">".into(), "<dt_array>".into()];
    for (index, name) in names.iter().enumerate() {
        result.push("<dt_assoc>".into());
        result.push(create_item("name", name));
        result.push(create_item("sortorder", &index.to_string()));
        result.push("</dt_assoc>".into());
    }
    result.push("</dt_array>".into());
    result.push("</item>".into());
    result.join("\n")
}

fn create_ns_assign(key: &str, value: &OpsVal) -> String {
    let names: Vec<String> = match value {
        OpsVal::List(items) => items
            .iter()
            .filter_map(|v| match v {
                OpsVal::Text(t) => Some(t.clone()),
                _ => None,
            })
            .collect(),
        _ => Vec::new(),
    };
    let mut result = vec![format!("<item key=\"{key}\">"), "<dt_array>".into()];
    for (index, name) in names.iter().enumerate() {
        result.push(create_item(&index.to_string(), name));
    }
    result.push("</dt_array>".into());
    result.push("</item>".into());
    result.join("\n")
}

#[derive(Debug, Clone)]
enum OpsNode {
    Text(String),
    Assoc(HashMap<String, OpsNode>),
    Array(Vec<OpsNode>),
}

impl OpsNode {
    fn as_text(&self) -> Option<&str> {
        match self {
            Self::Text(s) => Some(s),
            _ => None,
        }
    }

    fn assoc_get(&self, key: &str) -> Option<&OpsNode> {
        match self {
            Self::Assoc(map) => map.get(key),
            _ => None,
        }
    }

    fn as_array(&self) -> &[OpsNode] {
        match self {
            Self::Array(items) => items,
            _ => &[],
        }
    }
}

#[derive(Debug, Clone)]
struct XmlElem {
    name: String,
    attrs: HashMap<String, String>,
    text: String,
    children: Vec<XmlElem>,
}

fn parse_xml_tree(xml: &str) -> Result<XmlElem, DomainsError> {
    let mut reader = Reader::from_str(xml);
    reader.config_mut().trim_text(true);
    let mut stack: Vec<XmlElem> = Vec::new();
    let mut root: Option<XmlElem> = None;
    let mut buf = Vec::new();
    loop {
        match reader.read_event_into(&mut buf) {
            Ok(Event::Start(e)) => {
                let name = String::from_utf8_lossy(e.name().as_ref()).to_string();
                let mut attrs = HashMap::new();
                for attr in e.attributes().flatten() {
                    let key = String::from_utf8_lossy(attr.key.as_ref()).to_string();
                    let value = String::from_utf8_lossy(&attr.value).to_string();
                    attrs.insert(key, value);
                }
                stack.push(XmlElem {
                    name,
                    attrs,
                    text: String::new(),
                    children: Vec::new(),
                });
            }
            Ok(Event::Empty(e)) => {
                let name = String::from_utf8_lossy(e.name().as_ref()).to_string();
                let mut attrs = HashMap::new();
                for attr in e.attributes().flatten() {
                    let key = String::from_utf8_lossy(attr.key.as_ref()).to_string();
                    let value = String::from_utf8_lossy(&attr.value).to_string();
                    attrs.insert(key, value);
                }
                let elem = XmlElem {
                    name,
                    attrs,
                    text: String::new(),
                    children: Vec::new(),
                };
                if let Some(parent) = stack.last_mut() {
                    parent.children.push(elem);
                } else {
                    root = Some(elem);
                }
            }
            Ok(Event::End(_)) => {
                let Some(elem) = stack.pop() else {
                    break;
                };
                if let Some(parent) = stack.last_mut() {
                    parent.children.push(elem);
                } else {
                    root = Some(elem);
                }
            }
            Ok(Event::Text(t)) => {
                if let Some(elem) = stack.last_mut() {
                    elem.text.push_str(&t.unescape().unwrap_or_default());
                }
            }
            Ok(Event::CData(t)) => {
                if let Some(elem) = stack.last_mut() {
                    elem.text.push_str(&String::from_utf8_lossy(&t));
                }
            }
            Ok(Event::Eof) => break,
            Err(e) => {
                return Err(DomainsError::generic(
                    format!("Failed to parse OpenSRS XML: {e}"),
                    0,
                ));
            }
            _ => {}
        }
        buf.clear();
    }
    root.ok_or_else(|| DomainsError::generic("Failed to parse OpenSRS XML", 0))
}

fn elem_to_ops(elem: &XmlElem) -> OpsNode {
    match elem.name.as_str() {
        "dt_assoc" => {
            let mut map = HashMap::new();
            for child in &elem.children {
                if child.name == "item" {
                    if let Some(key) = child.attrs.get("key") {
                        map.insert(key.clone(), item_value(child));
                    }
                }
            }
            OpsNode::Assoc(map)
        }
        "dt_array" => {
            let mut items = Vec::new();
            for child in &elem.children {
                if child.name == "item" {
                    items.push(item_value(child));
                } else if child.name == "dt_assoc" {
                    items.push(elem_to_ops(child));
                }
            }
            OpsNode::Array(items)
        }
        _ => {
            if let Some(assoc) = elem.children.iter().find(|c| c.name == "dt_assoc") {
                elem_to_ops(assoc)
            } else if let Some(array) = elem.children.iter().find(|c| c.name == "dt_array") {
                elem_to_ops(array)
            } else {
                OpsNode::Text(elem.text.clone())
            }
        }
    }
}

fn item_value(item: &XmlElem) -> OpsNode {
    if let Some(assoc) = item.children.iter().find(|c| c.name == "dt_assoc") {
        elem_to_ops(assoc)
    } else if let Some(array) = item.children.iter().find(|c| c.name == "dt_array") {
        elem_to_ops(array)
    } else if !item.children.is_empty() {
        elem_to_ops(&item.children[0])
    } else {
        OpsNode::Text(item.text.clone())
    }
}

fn parse_ops(xml: &str) -> Result<OpsNode, DomainsError> {
    let tree = parse_xml_tree(xml)?;
    let body = find_child(&tree, "body").or_else(|| find_child(&tree, "OPS_envelope"));
    let envelope = if tree.name == "OPS_envelope" {
        &tree
    } else {
        find_child(&tree, "OPS_envelope").unwrap_or(&tree)
    };
    let body = body
        .or_else(|| find_child(envelope, "body"))
        .unwrap_or(envelope);
    let data_block = find_child(body, "data_block").unwrap_or(body);
    Ok(elem_to_ops(data_block))
}

fn find_child<'a>(elem: &'a XmlElem, name: &str) -> Option<&'a XmlElem> {
    if elem.name == name {
        return Some(elem);
    }
    for child in &elem.children {
        if child.name == name {
            return Some(child);
        }
        if let Some(found) = find_child(child, name) {
            return Some(found);
        }
    }
    None
}

impl Adapter for OpenSrs {
    fn get_name(&self) -> String {
        "opensrs".into()
    }

    fn available(&self, domain: &str) -> Result<bool, DomainsError> {
        let xml = self.send(
            "DOMAIN",
            "LOOKUP",
            &OpsVal::Map(vec![("domain".into(), OpsVal::text(domain))]),
            None,
        )?;
        let result = self.sanitize_response(&xml)?;
        let code = result
            .assoc_get("response_code")
            .and_then(OpsNode::as_text)
            .and_then(|s| s.parse::<i64>().ok())
            .unwrap_or(0);
        Ok(code == Self::RESPONSE_CODE_DOMAIN_AVAILABLE)
    }

    fn update_nameservers(
        &self,
        domain: &str,
        nameservers: Vec<String>,
    ) -> Result<NameserverUpdate, DomainsError> {
        let attrs = OpsVal::Map(vec![
            (
                "add_ns".into(),
                OpsVal::List(nameservers.iter().map(OpsVal::text).collect()),
            ),
            ("op_type".into(), OpsVal::text("add_remove")),
        ]);
        let xml = self.send(
            "DOMAIN",
            "ADVANCED_UPDATE_NAMESERVERS",
            &attrs,
            Some(domain),
        )?;
        let result = self.sanitize_response(&xml)?;
        let successful = result
            .assoc_get("is_success")
            .and_then(OpsNode::as_text)
            .unwrap_or("")
            == "1";
        let text = result
            .assoc_get("response_text")
            .and_then(OpsNode::as_text)
            .unwrap_or("")
            .to_string();
        let code = result
            .assoc_get("response_code")
            .and_then(OpsNode::as_text)
            .unwrap_or("")
            .to_string();
        Ok(NameserverUpdate {
            successful,
            nameservers,
            code: Some(code),
            text: Some(text),
            error: None,
        })
    }

    fn purchase(
        &self,
        domain: &str,
        contacts: Contacts,
        period_years: i64,
        nameservers: Vec<String>,
        autorenew_enabled: bool,
        purchase_price: Option<f64>,
    ) -> Result<String, DomainsError> {
        let nameservers = self.state.nameservers_or_default(nameservers);
        let contacts = self.sanitize_contacts(&contacts);
        match self.register(
            domain,
            Registrar::REG_TYPE_NEW,
            &contacts,
            &nameservers,
            period_years,
            None,
            purchase_price,
            autorenew_enabled,
        ) {
            Ok(xml) => match self.response(&xml) {
                Ok(result) => Ok(result.get("id").cloned().unwrap_or_default()),
                Err(e) => {
                    let message = format!("Failed to purchase domain: {}", e.message());
                    if e.code() == Self::RESPONSE_CODE_DOMAIN_TAKEN {
                        Err(DomainsError::domain_taken(message, e.code()))
                    } else if e.code() == Self::RESPONSE_CODE_INVALID_CONTACT
                        && e.message().contains("Invalid data")
                    {
                        Err(DomainsError::invalid_contact(message, e.code()))
                    } else if e.code() == Self::RESPONSE_CODE_INVALID_CONTACT
                        && e.message().contains("password")
                    {
                        Err(DomainsError::auth(message, e.code()))
                    } else {
                        Err(DomainsError::generic(message, e.code()))
                    }
                }
            },
            Err(e) => {
                let message = format!("Failed to purchase domain: {}", e.message());
                if e.code() == Self::RESPONSE_CODE_DOMAIN_TAKEN {
                    Err(DomainsError::domain_taken(message, e.code()))
                } else if e.code() == Self::RESPONSE_CODE_INVALID_CONTACT
                    && e.message().contains("Invalid data")
                {
                    Err(DomainsError::invalid_contact(message, e.code()))
                } else if e.code() == Self::RESPONSE_CODE_INVALID_CONTACT
                    && e.message().contains("password")
                {
                    Err(DomainsError::auth(message, e.code()))
                } else {
                    Err(DomainsError::generic(message, e.code()))
                }
            }
        }
    }

    fn transfer(
        &self,
        domain: &str,
        auth_code: &str,
        purchase_price: Option<f64>,
    ) -> Result<String, DomainsError> {
        match self.register(
            domain,
            Registrar::REG_TYPE_TRANSFER,
            &OpsVal::Map(Vec::new()),
            &[],
            1,
            Some(auth_code),
            purchase_price,
            false,
        ) {
            Ok(xml) => match self.response(&xml) {
                Ok(result) => Ok(result.get("id").cloned().unwrap_or_default()),
                Err(e) => map_transfer_error(e),
            },
            Err(e) => map_transfer_error(e),
        }
    }

    fn cancel_purchase(&self) -> Result<bool, DomainsError> {
        let timestamp = OffsetDateTime::now_utc().unix_timestamp();
        let attrs = OpsVal::Map(vec![
            ("to_date".into(), OpsVal::text(timestamp.to_string())),
            (
                "status".into(),
                OpsVal::List(vec![OpsVal::text("declined"), OpsVal::text("pending")]),
            ),
        ]);
        let xml = self.send("ORDER", "CANCEL_PENDING_ORDERS", &attrs, None)?;
        let result = self.sanitize_response(&xml)?;
        Ok(result
            .assoc_get("is_success")
            .and_then(OpsNode::as_text)
            .unwrap_or("")
            == "1")
    }

    fn suggest(
        &self,
        query: SuggestQuery,
        tlds: Vec<String>,
        limit: Option<i64>,
        filter_type: Option<&str>,
        price_max: Option<i64>,
        price_min: Option<i64>,
    ) -> Result<HashMap<String, SuggestItem>, DomainsError> {
        if let (Some(min), Some(max)) = (price_min, price_max) {
            if min > max {
                return Err(DomainsError::generic(
                    format!(
                        "Invalid price range: priceMin ({min}) must be less than priceMax ({max})."
                    ),
                    0,
                ));
            }
        }
        if let Some(filter) = filter_type {
            if filter != "premium" && filter != "suggestion" {
                return Err(DomainsError::generic(
                    format!("Invalid filter type: filterType ({filter}) must be 'premium' or 'suggestion'."),
                    0,
                ));
            }
            if filter == "suggestion" && (price_min.is_some() || price_max.is_some()) {
                return Err(DomainsError::generic(
                    format!(
                        "Invalid price range: priceMin ({}) and priceMax ({}) cannot be set when filterType is 'suggestion'.",
                        price_min.map_or_else(String::new, |v| v.to_string()),
                        price_max.map_or_else(String::new, |v| v.to_string())
                    ),
                    0,
                ));
            }
        }

        let search = query.as_terms().join(" ");
        let mut attrs = vec![
            (
                "services".into(),
                OpsVal::List(vec![
                    OpsVal::text("suggestion"),
                    OpsVal::text("premium"),
                    OpsVal::text("lookup"),
                ]),
            ),
            ("searchstring".into(), OpsVal::text(search)),
            ("skip_registry_lookup".into(), OpsVal::text("1")),
        ];
        let tlds: Vec<String> = tlds
            .into_iter()
            .map(|t| format!(".{}", t.trim_start_matches('.')))
            .collect();
        let mut service_override: Vec<(String, OpsVal)> = Vec::new();
        if !tlds.is_empty() {
            attrs.push((
                "tlds".into(),
                OpsVal::List(tlds.iter().map(OpsVal::text).collect()),
            ));
            let tld_list = OpsVal::List(tlds.iter().map(OpsVal::text).collect());
            if filter_type.is_none() || filter_type == Some("premium") {
                service_override.push((
                    "premium".into(),
                    OpsVal::Map(vec![("tlds".into(), tld_list.clone())]),
                ));
            }
            if filter_type.is_none() || filter_type == Some("suggestion") {
                service_override.push((
                    "suggestion".into(),
                    OpsVal::Map(vec![("tlds".into(), tld_list.clone())]),
                ));
            }
            service_override.push((
                "lookup".into(),
                OpsVal::Map(vec![("tlds".into(), tld_list)]),
            ));
        }
        if let Some(limit) = limit {
            let apply = |name: &str, so: &mut Vec<(String, OpsVal)>| {
                if let Some((_, OpsVal::Map(map))) = so.iter_mut().find(|(k, _)| k == name) {
                    map.push(("maximum".into(), OpsVal::text(limit.to_string())));
                } else {
                    so.push((
                        name.into(),
                        OpsVal::Map(vec![("maximum".into(), OpsVal::text(limit.to_string()))]),
                    ));
                }
            };
            if filter_type.is_none() || filter_type == Some("premium") {
                apply("premium", &mut service_override);
            }
            if filter_type.is_none() || filter_type == Some("suggestion") {
                apply("suggestion", &mut service_override);
            }
        }
        if let Some(min) = price_min {
            if let Some((_, OpsVal::Map(map))) =
                service_override.iter_mut().find(|(k, _)| k == "premium")
            {
                map.push(("price_min".into(), OpsVal::text(min.to_string())));
            } else {
                service_override.push((
                    "premium".into(),
                    OpsVal::Map(vec![("price_min".into(), OpsVal::text(min.to_string()))]),
                ));
            }
        }
        if let Some(max) = price_max {
            if let Some((_, OpsVal::Map(map))) =
                service_override.iter_mut().find(|(k, _)| k == "premium")
            {
                map.push(("price_max".into(), OpsVal::text(max.to_string())));
            } else {
                service_override.push((
                    "premium".into(),
                    OpsVal::Map(vec![("price_max".into(), OpsVal::text(max.to_string()))]),
                ));
            }
        }
        if !service_override.is_empty() {
            attrs.push(("service_override".into(), OpsVal::Map(service_override)));
        }

        let xml = self.send("DOMAIN", "NAME_SUGGEST", &OpsVal::Map(attrs), None)?;
        let result = self.sanitize_response(&xml)?;
        let mut items = HashMap::new();

        let empty: &[OpsNode] = &[];
        if filter_type.is_none() || filter_type == Some("suggestion") {
            let suggestion_items = result
                .assoc_get("attributes")
                .and_then(|a| a.assoc_get("suggestion"))
                .and_then(|s| s.assoc_get("items"))
                .map_or(empty, OpsNode::as_array);
            let mut processed = 0i64;
            for element in suggestion_items {
                if limit.is_some_and(|l| processed >= l) {
                    break;
                }
                let domain = element.assoc_get("domain").and_then(OpsNode::as_text);
                let status = element
                    .assoc_get("status")
                    .or_else(|| element.assoc_get("availability"))
                    .and_then(OpsNode::as_text)
                    .unwrap_or("")
                    .to_ascii_lowercase();
                let available = matches!(status.as_str(), "available" | "true" | "1");
                if let Some(domain) = domain {
                    items.insert(
                        domain.to_string(),
                        SuggestItem {
                            available,
                            price: None,
                            kind: "suggestion".into(),
                            renewal_price: None,
                            purchase_type: None,
                        },
                    );
                    processed += 1;
                }
            }
            if filter_type == Some("suggestion") {
                return Ok(items);
            }
            if limit.is_some_and(|l| items.len() as i64 >= l) {
                return Ok(items);
            }
        }

        if !(limit.is_some_and(|l| items.len() as i64 >= l)) {
            let premium_items = result
                .assoc_get("attributes")
                .and_then(|a| a.assoc_get("premium"))
                .and_then(|s| s.assoc_get("items"))
                .map_or(empty, OpsNode::as_array);
            let remaining = limit.map(|l| l - items.len() as i64);
            let mut processed = 0i64;
            for element in premium_items {
                if remaining.is_some_and(|r| processed >= r) {
                    break;
                }
                let domain = element.assoc_get("domain").and_then(OpsNode::as_text);
                let available = element
                    .assoc_get("status")
                    .and_then(OpsNode::as_text)
                    .is_some_and(|s| s == "available");
                let price = element
                    .assoc_get("price")
                    .and_then(OpsNode::as_text)
                    .and_then(|s| s.parse::<f64>().ok());
                if let Some(domain) = domain {
                    items.insert(
                        domain.to_string(),
                        SuggestItem {
                            available,
                            price,
                            kind: "premium".into(),
                            renewal_price: None,
                            purchase_type: None,
                        },
                    );
                    processed += 1;
                }
            }
        }
        Ok(items)
    }

    fn get_price(
        &self,
        domain: &str,
        period_years: i64,
        reg_type: &str,
        ttl: u64,
    ) -> Result<Price, DomainsError> {
        if let Some(cache) = &self.state.cache {
            if let Some(Value::Object(cached)) = cache.load(domain, ttl) {
                if let Some(price) = cached.get("price").and_then(Value::as_f64) {
                    let premium = cached
                        .get("premium")
                        .and_then(Value::as_bool)
                        .unwrap_or(false);
                    return Ok(Price::new(price, premium));
                }
            }
        }
        match self.send(
            "DOMAIN",
            "GET_PRICE",
            &OpsVal::Map(vec![
                ("domain".into(), OpsVal::text(domain)),
                ("periodYears".into(), OpsVal::text(period_years.to_string())),
                ("reg_type".into(), OpsVal::text(reg_type)),
            ]),
            None,
        ) {
            Ok(xml) => match self.sanitize_response(&xml) {
                Ok(result) => {
                    let price = result
                        .assoc_get("attributes")
                        .and_then(|a| a.assoc_get("price"))
                        .and_then(OpsNode::as_text)
                        .and_then(|s| s.parse::<f64>().ok());
                    let Some(price) = price else {
                        return Err(DomainsError::price_not_found(
                            format!("Price not found for domain: {domain}"),
                            Self::RESPONSE_CODE_DOMAIN_PRICE_NOT_FOUND,
                        ));
                    };
                    let price_obj = Price::new(price, false);
                    if let Some(cache) = &self.state.cache {
                        cache.save(
                            domain,
                            json!({ "price": price_obj.price, "premium": price_obj.premium }),
                        );
                    }
                    Ok(price_obj)
                }
                Err(e) => {
                    let message = format!("Failed to get price for domain: {}", e.message());
                    if e.code() == Self::RESPONSE_CODE_DOMAIN_PRICE_NOT_FOUND {
                        Err(DomainsError::price_not_found(message, e.code()))
                    } else {
                        Err(DomainsError::generic(message, e.code()))
                    }
                }
            },
            Err(e) => {
                let message = format!("Failed to get price for domain: {}", e.message());
                if e.code() == Self::RESPONSE_CODE_DOMAIN_PRICE_NOT_FOUND {
                    Err(DomainsError::price_not_found(message, e.code()))
                } else {
                    Err(DomainsError::generic(message, e.code()))
                }
            }
        }
    }

    fn tlds(&self) -> Result<Vec<String>, DomainsError> {
        Ok(Vec::new())
    }

    fn get_domain(&self, domain: &str) -> Result<RegistrarDomain, DomainsError> {
        let xml = self.send(
            "DOMAIN",
            "GET",
            &OpsVal::Map(vec![
                ("type".into(), OpsVal::text("all_info")),
                ("clean_ca_subset".into(), OpsVal::text("1")),
            ]),
            Some(domain),
        )?;
        let result = self.sanitize_response(&xml)?;
        let attrs = result.assoc_get("attributes");
        let created = attrs
            .and_then(|a| a.assoc_get("registry_createdate"))
            .and_then(OpsNode::as_text)
            .and_then(parse_datetime);
        let expires = attrs
            .and_then(|a| a.assoc_get("registry_expiredate"))
            .and_then(OpsNode::as_text)
            .and_then(parse_datetime);
        let auto_renew = attrs
            .and_then(|a| a.assoc_get("auto_renew"))
            .and_then(OpsNode::as_text)
            .map(|v| v == "1");
        let nameservers = attrs
            .and_then(|a| a.assoc_get("nameserver_list"))
            .map(|list| {
                list.as_array()
                    .iter()
                    .filter_map(|item| {
                        item.assoc_get("name")
                            .and_then(OpsNode::as_text)
                            .map(str::trim)
                            .filter(|s| !s.is_empty())
                            .map(str::to_string)
                    })
                    .collect::<Vec<_>>()
            });
        Ok(RegistrarDomain::new(
            domain,
            created,
            expires,
            auto_renew,
            nameservers,
        ))
    }

    fn update_domain(&self, domain: &str, details: &UpdateDetails) -> Result<bool, DomainsError> {
        let Some(auto_renew) = details.auto_renew else {
            return Err(DomainsError::generic("Details must include autoRenew", 400));
        };
        let attrs = OpsVal::Map(vec![
            ("data".into(), OpsVal::text("expire_action")),
            ("affect_domains".into(), OpsVal::text("0")),
            (
                "auto_renew".into(),
                OpsVal::text(if auto_renew { "1" } else { "0" }),
            ),
            (
                "let_expire".into(),
                OpsVal::text(if auto_renew { "0" } else { "1" }),
            ),
        ]);
        let xml = self.send("DOMAIN", "MODIFY", &attrs, Some(domain))?;
        let result = self.sanitize_response(&xml)?;
        let code = result
            .assoc_get("response_code")
            .and_then(OpsNode::as_text)
            .and_then(|s| s.parse::<i64>().ok())
            .unwrap_or(0);
        if code == Self::RESPONSE_CODE_NOTHING_TO_DO {
            return Ok(true);
        }
        let details_success = result
            .assoc_get("attributes")
            .and_then(|a| a.assoc_get("details"))
            .and_then(|d| d.assoc_get(domain))
            .and_then(|d| d.assoc_get("is_success"))
            .and_then(OpsNode::as_text);
        let success =
            details_success.or_else(|| result.assoc_get("is_success").and_then(OpsNode::as_text));
        let Some(success) = success else {
            return Err(DomainsError::generic(
                "Failed to update domain: invalid response from OpenSRS",
                500,
            ));
        };
        Ok(success == "1")
    }

    fn renew(&self, domain: &str, period_years: i64) -> Result<Renewal, DomainsError> {
        let xml = self.send(
            "DOMAIN",
            "RENEW",
            &OpsVal::Map(vec![
                ("domain".into(), OpsVal::text(domain)),
                ("auto_renew".into(), OpsVal::text("0")),
                ("currentexpirationyear".into(), OpsVal::text("2022")),
                ("periodYears".into(), OpsVal::text(period_years.to_string())),
                ("handle".into(), OpsVal::text("process")),
            ]),
            None,
        )?;
        let result = parse_ops(&xml)?;
        let attrs = result.assoc_get("attributes");
        let order_id = attrs
            .and_then(|a| a.assoc_get("order_id"))
            .and_then(OpsNode::as_text)
            .map(str::to_string);
        let expires = attrs
            .and_then(|a| a.assoc_get("registration expiration date"))
            .and_then(OpsNode::as_text)
            .and_then(parse_datetime);
        Ok(Renewal::new(order_id, expires))
    }

    fn get_auth_code(&self, domain: &str) -> Result<String, DomainsError> {
        match self.send(
            "DOMAIN",
            "GET",
            &OpsVal::Map(vec![("type".into(), OpsVal::text("domain_auth_info"))]),
            Some(domain),
        ) {
            Ok(xml) => {
                let result = self.sanitize_response(&xml)?;
                result
                    .assoc_get("attributes")
                    .and_then(|a| a.assoc_get("domain_auth_info"))
                    .and_then(OpsNode::as_text)
                    .map(str::to_string)
                    .ok_or_else(|| DomainsError::generic("Auth code not found in response", 404))
                    .map_err(|e| {
                        DomainsError::generic(
                            format!("Failed to get auth code: {}", e.message()),
                            e.code(),
                        )
                    })
            }
            Err(e) => Err(DomainsError::generic(
                format!("Failed to get auth code: {}", e.message()),
                e.code(),
            )),
        }
    }

    fn check_transfer_status(&self, domain: &str) -> Result<TransferStatus, DomainsError> {
        match self.send(
            "DOMAIN",
            "CHECK_TRANSFER",
            &OpsVal::Map(vec![
                ("domain".into(), OpsVal::text(domain)),
                ("check_status".into(), OpsVal::text("1")),
                ("get_request_address".into(), OpsVal::text("0")),
            ]),
            None,
        ) {
            Ok(xml) => {
                let result = self.sanitize_response(&xml)?;
                let attrs = result.assoc_get("attributes");
                let transferrable = attrs
                    .and_then(|a| a.assoc_get("transferrable"))
                    .and_then(OpsNode::as_text)
                    .and_then(|s| s.parse::<i64>().ok())
                    .unwrap_or(0);
                let noservice = attrs
                    .and_then(|a| a.assoc_get("noservice"))
                    .and_then(OpsNode::as_text)
                    .and_then(|s| s.parse::<i64>().ok())
                    .unwrap_or(0);
                let reason = attrs
                    .and_then(|a| a.assoc_get("reason"))
                    .and_then(OpsNode::as_text)
                    .map(str::to_string);
                let status_str = attrs
                    .and_then(|a| a.assoc_get("status"))
                    .and_then(OpsNode::as_text);
                let timestamp = attrs
                    .and_then(|a| a.assoc_get("timestamp"))
                    .and_then(OpsNode::as_text)
                    .and_then(parse_datetime);
                let status = if noservice == 1 {
                    TransferStatusEnum::ServiceUnavailable
                } else if transferrable == 1 {
                    TransferStatusEnum::Transferrable
                } else {
                    match status_str {
                        Some("pending_owner") => TransferStatusEnum::PendingOwner,
                        Some("pending_admin") => TransferStatusEnum::PendingAdmin,
                        Some("pending_registry") => TransferStatusEnum::PendingRegistry,
                        Some("completed") => TransferStatusEnum::Completed,
                        Some("cancelled") => TransferStatusEnum::Cancelled,
                        _ => TransferStatusEnum::NotTransferrable,
                    }
                };
                Ok(TransferStatus::new(status, reason, timestamp))
            }
            Err(e) => Err(DomainsError::generic(
                format!("Failed to check transfer status: {}", e.message()),
                e.code(),
            )),
        }
    }

    fn set_default_nameservers(&mut self, nameservers: Vec<String>) {
        self.state.default_nameservers = nameservers;
    }

    fn set_cache(&mut self, cache: Option<Cache>) {
        self.state.cache = cache;
    }

    fn set_connect_timeout(&mut self, connect_timeout: u64) {
        self.state.connect_timeout = connect_timeout;
    }

    fn set_timeout(&mut self, timeout: u64) {
        self.state.timeout = timeout;
    }
}
