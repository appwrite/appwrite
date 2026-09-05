use parking_lot::Mutex;
use serde_json::Value;
use utopia_validators::{Validator, ValueType};

use crate::client::Client;
use crate::message::{Question, Record};
use crate::Message;

/// PHP `Utopia\DNS\Validator\DNS`.
#[derive(Debug)]
pub struct DNS {
    target: String,
    type_code: u16,
    dns_server: String,
    records: Mutex<Vec<String>>,
    value: Mutex<String>,
    count: Mutex<usize>,
    reason: Mutex<String>,
}

impl DNS {
    const FAILURE_REASON_QUERY: &'static str = "DNS query failed.";
    const FAILURE_REASON_INTERNAL: &'static str = "Internal error occurred.";
    const FAILURE_REASON_UNKNOWN: &'static str = "";
    pub const DEFAULT_DNS_SERVER: &'static str = "8.8.8.8";

    #[must_use]
    pub fn new(target: impl Into<String>, type_code: u16, dns_server: impl Into<String>) -> Self {
        Self {
            target: target.into(),
            type_code,
            dns_server: dns_server.into(),
            records: Mutex::new(Vec::new()),
            value: Mutex::new(String::new()),
            count: Mutex::new(0),
            reason: Mutex::new(String::new()),
        }
    }

    pub fn records(&self) -> Vec<String> {
        self.records.lock().clone()
    }
}

impl Validator for DNS {
    fn description(&self) -> String {
        let reason = self.reason.lock().clone();
        if !reason.is_empty() && reason != "0" {
            return reason;
        }
        let type_verbose = Record::type_code_to_name(self.type_code)
            .map_or_else(|| self.type_code.to_string(), str::to_string);
        let mut messages = vec![format!(
            "DNS verification failed with resolver {}",
            self.dns_server
        )];
        let count = *self.count.lock();
        let value = self.value.lock().clone();
        let records = self.records.lock().join("', '");
        if count == 0 {
            messages.push(format!("Domain {value} is missing {type_verbose} record"));
            return format!("{}.", messages.join(". "));
        }
        let count_verbose = match count {
            1 => "one",
            2 => "two",
            3 => "three",
            4 => "four",
            5 => "five",
            6 => "six",
            7 => "seven",
            8 => "eight",
            9 => "nine",
            10 => "ten",
            n => {
                return {
                    messages.push(format!(
                        "Domain {value} has {n} incompatible {type_verbose} records: '{records}'"
                    ));
                    extra_caa(&mut messages, self.type_code);
                    format!("{}.", messages.join(". "))
                };
            }
        };
        if count == 1 {
            messages.push(format!(
                "Domain {value} has incorrect {type_verbose} value '{records}'"
            ));
        } else {
            messages.push(format!(
                "Domain {value} has {count_verbose} incompatible {type_verbose} records: '{records}'"
            ));
        }
        extra_caa(&mut messages, self.type_code);
        format!("{}.", messages.join(". "))
    }

    fn value_type(&self) -> ValueType {
        ValueType::String
    }

    fn is_valid(&self, value: &Value) -> bool {
        let Some(value) = value.as_str() else {
            *self.reason.lock() = Self::FAILURE_REASON_INTERNAL.into();
            return false;
        };
        *self.count.lock() = 0;
        *self.value.lock() = value.to_string();
        *self.reason.lock() = Self::FAILURE_REASON_UNKNOWN.into();
        self.records.lock().clear();

        let Ok(dns) = Client::new(&self.dns_server, 53, 5, false) else {
            *self.reason.lock() = Self::FAILURE_REASON_QUERY.into();
            return false;
        };
        let question = Question::new(value, self.type_code);
        let Ok(query_message) = Message::query(question, None, true) else {
            *self.reason.lock() = Self::FAILURE_REASON_QUERY.into();
            return false;
        };
        let Ok(response) = dns.query(&query_message) else {
            *self.reason.lock() = Self::FAILURE_REASON_QUERY.into();
            return false;
        };
        let query: Vec<&Record> = response
            .answers
            .iter()
            .filter(|r| r.type_code == self.type_code)
            .collect();
        *self.count.lock() = query.len();
        if query.is_empty() {
            if self.type_code == Record::TYPE_CAA {
                if let Ok(domain) = utopia_domains::Domain::new(value) {
                    if domain.get() == domain.get_apex() {
                        return true;
                    }
                    let mut parts: Vec<&str> = value.split('.').collect();
                    if parts.len() < 2 {
                        return false;
                    }
                    parts.remove(0);
                    let parent = parts.join(".");
                    let validator = Self::new(&self.target, self.type_code, &self.dns_server);
                    let result = validator.is_valid(&Value::String(parent));
                    *self.records.lock() = validator.records();
                    self.value.lock().clone_from(&validator.value.lock());
                    *self.count.lock() = *validator.count.lock();
                    self.reason.lock().clone_from(&validator.reason.lock());
                    return result;
                }
            }
            return false;
        }
        for record in query {
            if self.type_code == Record::TYPE_CAA {
                let extracted = extract_caa_domain(&record.rdata);
                self.records.lock().push(extracted.clone());
                if extracted == self.target {
                    return true;
                }
            } else {
                self.records.lock().push(record.rdata.clone());
            }
            if record.rdata == self.target {
                return true;
            }
        }
        false
    }
}

fn extra_caa(messages: &mut Vec<String>, type_code: u16) {
    if type_code == Record::TYPE_CAA {
        messages.push("Add new CAA record, or remove all other CAA records".into());
    }
}

fn extract_caa_domain(rdata: &str) -> String {
    let third = rdata.splitn(3, ' ').nth(2).unwrap_or("");
    let unquoted = third.trim_matches('"');
    unquoted.split(';').next().unwrap_or("").to_string()
}
