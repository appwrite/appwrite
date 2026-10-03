use super::Zone;
use crate::error::Result;
use crate::message::{Message, Question, Record};
use rand::seq::SliceRandom;

/// Zone lookup. PHP `Utopia\DNS\Zone\Resolver`.
#[derive(Debug)]
pub struct Resolver;

impl Resolver {
    /// PHP `Zone\Resolver::lookup`.
    pub fn lookup(query: &Message, zone: &Zone) -> Result<Message> {
        let Some(question) = query.questions.first() else {
            return Message::response(
                &query.header,
                Message::RCODE_FORMERR,
                Vec::new(),
                Vec::new(),
                Vec::new(),
                Vec::new(),
                true,
                false,
                false,
            );
        };

        let records = select_best_records(query, zone);
        if records.is_empty() {
            if question.type_code == Record::TYPE_SOA && question.name == zone.name {
                return soa_apex_response(query, zone);
            }
            return Message::response(
                &query.header,
                Message::RCODE_NXDOMAIN,
                query.questions.clone(),
                Vec::new(),
                vec![zone.soa.clone()],
                Vec::new(),
                true,
                false,
                false,
            );
        }

        let rname = &records[0].name;
        if rname == &question.name {
            return handle_exact_match(&records, query, zone);
        }
        if is_wildcard_match(&question.name, rname) {
            return handle_wildcard_match(&records, query, zone);
        }
        Message::response(
            &query.header,
            Message::RCODE_NXDOMAIN,
            query.questions.clone(),
            Vec::new(),
            vec![zone.soa.clone()],
            Vec::new(),
            true,
            false,
            false,
        )
    }
}

fn select_best_records(query: &Message, zone: &Zone) -> Vec<Record> {
    let question = &query.questions[0];
    let exact: Vec<Record> = zone
        .records
        .iter()
        .filter(|r| r.name == question.name)
        .cloned()
        .collect();
    if !exact.is_empty() {
        return exact;
    }
    if let Some(wildcard) = find_closest_wildcard(&question.name, zone) {
        return zone
            .records
            .iter()
            .filter(|r| r.name == wildcard.name)
            .cloned()
            .collect();
    }
    Vec::new()
}

fn find_closest_wildcard(question_name: &str, zone: &Zone) -> Option<Record> {
    let parts: Vec<&str> = question_name.split('.').collect();
    for i in 1..parts.len() {
        let wildcard_name = format!("*.{}", parts[i..].join("."));
        if let Some(record) = zone.records.iter().find(|r| r.name == wildcard_name) {
            return Some(record.clone());
        }
    }
    None
}

fn handle_exact_match(records: &[Record], query: &Message, zone: &Zone) -> Result<Message> {
    let question = &query.questions[0];
    let is_authoritative = zone.is_authoritative(&question.name);
    if is_authoritative {
        if question.type_code == Record::TYPE_SOA && question.name == zone.name {
            return soa_apex_response(query, zone);
        }
        let exact_type: Vec<Record> = records
            .iter()
            .filter(|r| r.type_code == question.type_code)
            .cloned()
            .collect();
        if !exact_type.is_empty() {
            return Message::response(
                &query.header,
                Message::RCODE_NOERROR,
                query.questions.clone(),
                randomize_rrset(exact_type),
                Vec::new(),
                Vec::new(),
                true,
                false,
                false,
            );
        }
        let cname: Vec<Record> = records
            .iter()
            .filter(|r| r.type_code == Record::TYPE_CNAME)
            .cloned()
            .collect();
        if !cname.is_empty() {
            return Message::response(
                &query.header,
                Message::RCODE_NOERROR,
                query.questions.clone(),
                cname,
                Vec::new(),
                Vec::new(),
                true,
                false,
                false,
            );
        }
        return Message::response(
            &query.header,
            Message::RCODE_NOERROR,
            query.questions.clone(),
            Vec::new(),
            vec![zone.soa.clone()],
            Vec::new(),
            true,
            false,
            false,
        );
    }
    let ns: Vec<Record> = records
        .iter()
        .filter(|r| r.type_code == Record::TYPE_NS)
        .cloned()
        .collect();
    Message::response(
        &query.header,
        Message::RCODE_NOERROR,
        query.questions.clone(),
        Vec::new(),
        ns,
        Vec::new(),
        false,
        false,
        false,
    )
}

fn soa_apex_response(query: &Message, zone: &Zone) -> Result<Message> {
    Message::response(
        &query.header,
        Message::RCODE_NOERROR,
        query.questions.clone(),
        vec![zone.soa.clone()],
        Vec::new(),
        Vec::new(),
        true,
        false,
        false,
    )
}

fn randomize_rrset(mut records: Vec<Record>) -> Vec<Record> {
    if records.len() <= 1 {
        return records;
    }
    records.shuffle(&mut rand::thread_rng());
    records
}

fn is_wildcard_match(query_name: &str, record_name: &str) -> bool {
    let Some(suffix) = record_name.strip_prefix("*.") else {
        return false;
    };
    let parts: Vec<&str> = query_name.split('.').collect();
    if parts.len() < 2 {
        return false;
    }
    parts[1..].join(".") == suffix
}

fn handle_wildcard_match(records: &[Record], query: &Message, zone: &Zone) -> Result<Message> {
    let question: &Question = &query.questions[0];
    let exact_type: Vec<Record> = records
        .iter()
        .filter(|r| r.type_code == question.type_code)
        .cloned()
        .collect();
    if !exact_type.is_empty() {
        let synthesized: Vec<Record> = exact_type
            .into_iter()
            .map(|r| r.with_name(&question.name))
            .collect();
        return Message::response(
            &query.header,
            Message::RCODE_NOERROR,
            query.questions.clone(),
            randomize_rrset(synthesized),
            Vec::new(),
            Vec::new(),
            true,
            false,
            false,
        );
    }
    let cname: Vec<Record> = records
        .iter()
        .filter(|r| r.type_code == Record::TYPE_CNAME)
        .cloned()
        .collect();
    if !cname.is_empty() {
        let synthesized: Vec<Record> = cname
            .into_iter()
            .map(|r| r.with_name(&question.name))
            .collect();
        return Message::response(
            &query.header,
            Message::RCODE_NOERROR,
            query.questions.clone(),
            synthesized,
            Vec::new(),
            Vec::new(),
            true,
            false,
            false,
        );
    }
    Message::response(
        &query.header,
        Message::RCODE_NOERROR,
        query.questions.clone(),
        Vec::new(),
        vec![zone.soa.clone()],
        Vec::new(),
        true,
        false,
        false,
    )
}
