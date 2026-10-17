//! Looking a query up in a zone: exact names, wildcards, `CNAME`s,
//! delegations and negative answers.

use std::cell::RefCell;

use php_std::random::Mt19937;

use super::Zone;
use crate::Error;
use crate::message::{Message, RCODE_FORMERR, RCODE_NOERROR, RCODE_NXDOMAIN, Record};

thread_local! {
    static RNG: RefCell<Mt19937> = RefCell::new(Mt19937::from_entropy());
}

/// Answers `query` from `zone`, shuffling multi-record answers with a
/// generator seeded from the operating system.
///
/// See [`lookup_with`].
///
/// PHP: `Zone\Resolver::lookup()`.
pub fn lookup(query: &Message, zone: &Zone) -> Result<Message, Error> {
    RNG.with(|rng| lookup_with(query, zone, &mut rng.borrow_mut()))
}

/// Answers `query` from `zone` (the algorithm of
/// <https://www.usenix.org/sites/default/files/styles/article_embedded/public/tree.png>):
///
/// - no question: `FORMERR`;
/// - records named exactly like the question: the records of its type
///   (shuffled, RFC 2181 §5), else its `CNAME`s, else no data with the
///   `SOA`; a name with `NS` records below the apex is a referral to them;
/// - otherwise the closest enclosing wildcard's records, renamed to the
///   question's name, chosen the same way;
/// - otherwise `NXDOMAIN` with the `SOA` (an `SOA` question at the apex is
///   answered with it).
///
/// Shuffles draw from `rng` as PHP's `shuffle()` draws from its Mersenne
/// Twister, so a seeded generator orders answers as PHP after `mt_srand()`.
///
/// PHP: `Zone\Resolver::lookup()`.
pub fn lookup_with(query: &Message, zone: &Zone, rng: &mut Mt19937) -> Result<Message, Error> {
    let Some(question) = query.questions().first() else {
        return Message::response(query.header(), RCODE_FORMERR).authoritative(true).build();
    };
    let name = question.name();
    let records = best_records(name, zone);
    if records.is_empty() {
        if question.ty == Record::TYPE_SOA && name == zone.name() {
            return soa_apex(query, zone);
        }
        return nxdomain(query, zone);
    }
    let owner = records[0].name();
    if owner == name {
        return exact(records, query, zone, rng);
    }
    if is_wildcard_match(name, owner) {
        return wildcard(records, query, zone, rng);
    }
    nxdomain(query, zone)
}

fn nxdomain(query: &Message, zone: &Zone) -> Result<Message, Error> {
    Message::response(query.header(), RCODE_NXDOMAIN)
        .questions(query.questions().to_vec())
        .authority(vec![zone.soa().clone()])
        .authoritative(true)
        .build()
}

fn soa_apex(query: &Message, zone: &Zone) -> Result<Message, Error> {
    Message::response(query.header(), RCODE_NOERROR)
        .questions(query.questions().to_vec())
        .answers(vec![zone.soa().clone()])
        .authoritative(true)
        .build()
}

fn nodata(query: &Message, zone: &Zone) -> Result<Message, Error> {
    Message::response(query.header(), RCODE_NOERROR)
        .questions(query.questions().to_vec())
        .authority(vec![zone.soa().clone()])
        .authoritative(true)
        .build()
}

fn answer(query: &Message, answers: Vec<Record>) -> Result<Message, Error> {
    Message::response(query.header(), RCODE_NOERROR)
        .questions(query.questions().to_vec())
        .answers(answers)
        .authoritative(true)
        .build()
}

/// The records named like the question, else those of its closest
/// enclosing wildcard.
fn best_records<'z>(name: &[u8], zone: &'z Zone) -> Vec<&'z Record> {
    let exact: Vec<&Record> = zone.records().iter().filter(|r| r.name() == name).collect();
    if !exact.is_empty() {
        return exact;
    }
    match closest_wildcard(name, zone) {
        Some(wildcard) => zone.records().iter().filter(|r| r.name() == wildcard).collect(),
        None => Vec::new(),
    }
}

/// `*.b.c.example.com`, then `*.c.example.com`, ...: the first a record has.
fn closest_wildcard<'z>(name: &[u8], zone: &'z Zone) -> Option<&'z [u8]> {
    let mut candidate = Vec::with_capacity(name.len() + 2);
    let mut rest = name;
    while let Some(dot) = rest.iter().position(|&b| b == b'.') {
        rest = &rest[dot + 1..];
        candidate.clear();
        candidate.extend_from_slice(b"*.");
        candidate.extend_from_slice(rest);
        if let Some(record) = zone.records().iter().find(|r| r.name() == candidate.as_slice()) {
            return Some(record.name());
        }
    }
    None
}

/// Whether `owner` (`*.suffix`) covers `name`: everything after its first
/// label is the suffix.
fn is_wildcard_match(name: &[u8], owner: &[u8]) -> bool {
    let Some(suffix) = owner.strip_prefix(b"*.") else {
        return false;
    };
    let rest = match name.iter().position(|&b| b == b'.') {
        Some(dot) => &name[dot + 1..],
        None => b"",
    };
    rest == suffix
}

fn exact(records: Vec<&Record>, query: &Message, zone: &Zone, rng: &mut Mt19937) -> Result<Message, Error> {
    let question = &query.questions()[0];
    if !zone.is_authoritative(question.name()) {
        let ns = records.into_iter().filter(|r| r.ty() == Record::TYPE_NS).cloned().collect();
        return Message::response(query.header(), RCODE_NOERROR)
            .questions(query.questions().to_vec())
            .authority(ns)
            .build();
    }
    if question.ty == Record::TYPE_SOA && question.name() == zone.name() {
        return soa_apex(query, zone);
    }
    let of_type: Vec<Record> = records.iter().filter(|r| r.ty() == question.ty).map(|r| (*r).clone()).collect();
    if !of_type.is_empty() {
        return answer(query, shuffle(of_type, rng));
    }
    let cnames: Vec<Record> = records.iter().filter(|r| r.ty() == Record::TYPE_CNAME).map(|r| (*r).clone()).collect();
    if !cnames.is_empty() {
        return answer(query, cnames);
    }
    nodata(query, zone)
}

fn wildcard(records: Vec<&Record>, query: &Message, zone: &Zone, rng: &mut Mt19937) -> Result<Message, Error> {
    let question = &query.questions()[0];
    let of_type: Vec<Record> =
        records.iter().filter(|r| r.ty() == question.ty).map(|r| (*r).clone().with_name(question.name())).collect();
    if !of_type.is_empty() {
        return answer(query, shuffle(of_type, rng));
    }
    let cnames: Vec<Record> = records
        .iter()
        .filter(|r| r.ty() == Record::TYPE_CNAME)
        .map(|r| (*r).clone().with_name(question.name()))
        .collect();
    if !cnames.is_empty() {
        return answer(query, cnames);
    }
    nodata(query, zone)
}

/// PHP's `shuffle()`: Fisher-Yates from the end, each swap index drawn as
/// `mt_rand(0, n)`.
fn shuffle(mut records: Vec<Record>, rng: &mut Mt19937) -> Vec<Record> {
    if records.len() <= 1 {
        return records;
    }
    let mut left = records.len() - 1;
    while left > 0 {
        let index = rng.range(0, left as i64) as usize;
        if index != left {
            records.swap(left, index);
        }
        left -= 1;
    }
    records
}
