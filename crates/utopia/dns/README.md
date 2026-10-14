# Utopia DNS

> [!IMPORTANT]
> This repository is a read-only mirror of [`crates/utopia/dns`](https://github.com/appwrite/appwrite/tree/main/crates/utopia/dns) in [appwrite/appwrite](https://github.com/appwrite/appwrite). Development happens there — please open issues and pull requests against appwrite/appwrite.

[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://discord.gg/GSeTUeA)

DNS messages, zones and zone files, resolvers, a server over UDP, TCP and DNS over HTTPS, a client, a recursive lookup and record validators.

This library is maintained by the [Appwrite team](https://appwrite.io).

## Getting started

```toml
[dependencies]
utopia-dns = { git = "https://github.com/utopia-rs/dns" }
```

A DNS server receives small binary packets, decodes a question, finds the records that answer it and sends an encoded response back, over UDP, TCP or HTTPS. This library covers each step: the message codec, zones and zone files, resolvers, a server on Tokio, a client, and validators for what users type into DNS settings. This guide covers the common tasks.

## Encode and decode messages

`Message::query` builds a query; `Message::decode` and `encode` convert to and from the wire format. Names and record data are bytes.

```rust
use utopia_dns::message::{Message, Question, Record};

let query = Message::query(Question::new("www.example.com", Record::TYPE_A), Some(0x1a2b), true);
let wire = query.encode(None)?;
assert_eq!(&wire[..2], &[0x1a, 0x2b]);

let decoded = Message::decode(&wire)?;
assert_eq!(decoded.questions()[0].name(), b"www.example.com");
assert_eq!(decoded.questions()[0].ty, Record::TYPE_A);
```

## Answer from a zone

A `Zone` holds the records of a domain and its `SOA`. `zone::lookup` answers a query with exact matches, wildcards, `CNAME`s, delegations and negative answers.

```rust
use utopia_dns::message::{Message, Question, Record, RCODE_NXDOMAIN};
use utopia_dns::{zone, Zone};

let zone = Zone::new(
    "example.com",
    vec![Record::new("*.example.com", Record::TYPE_A).with_ttl(60).with_rdata("192.0.2.1")],
    Record::new("example.com", Record::TYPE_SOA)
        .with_ttl(3600)
        .with_rdata("ns1.example.com hostmaster.example.com 1 7200 3600 1209600 300"),
)?;

let answer = zone::lookup(&Message::query(Question::new("app.example.com", Record::TYPE_A), Some(1), true), &zone)?;
assert_eq!(answer.answers()[0].name(), b"app.example.com");
assert_eq!(answer.answers()[0].rdata(), b"192.0.2.1");

let missing = zone::lookup(&Message::query(Question::new("example.org", Record::TYPE_A), Some(2), true), &zone)?;
assert_eq!(missing.header().response_code, RCODE_NXDOMAIN);
```

## Import a zone file

`zone::import` reads the master file format; `zone::export` writes it back.

```rust
use utopia_dns::zone;

let zone = zone::import(b"$ORIGIN example.com.
@ IN SOA ns1 admin 2025011801 7200 3600 1209600 1800
www 300 IN A 192.0.2.10
mail IN MX 10 www", None, 3600)?;

assert_eq!(zone.records().len(), 2);
assert_eq!(zone.records()[1].rdata(), b"www.example.com");
let file = zone::export(&zone, false);
assert!(file.starts_with(b"$ORIGIN example.com.\n$TTL 3600\n"));
```

## Serve

A `Server` answers with a resolver; the Tokio adapter listens on UDP, TCP and DNS over HTTPS. `Handler::handle` is what the adapter calls for each packet, so a server can be tried without sockets.

```rust
use utopia_dns::message::{Message, Question, Record};
use utopia_dns::resolver::Memory;
use utopia_dns::{Handler, Protocol, Server, Zone};

let zone = Zone::new(
    "example.test",
    vec![Record::new("www.example.test", Record::TYPE_TXT).with_ttl(60).with_rdata("hello")],
    Record::new("example.test", Record::TYPE_SOA).with_ttl(60).with_rdata("ns1 hostmaster 1 7200 1800 1209600 3600"),
)?;
let server = Server::new(Memory::new(zone));

let query = Message::query(Question::new("www.example.test", Record::TYPE_TXT), Some(9), true);
let packet = server.handle(&query.encode(None)?, "192.0.2.7", 53000, Protocol::Udp).await;
assert_eq!(Message::decode(&packet)?.answers()[0].rdata(), b"hello");
```

## Validate user input

`validator::Name` checks record names (underscores are fine except for `A`/`AAAA` owners) and `validator::Caa` checks CAA values.

```rust
use serde_json::json;
use utopia_dns::message::Record;
use utopia_dns::validator::{Caa, Name};
use utopia_validators::Validator;

assert!(Name::new(Some(Record::TYPE_TXT)).is_valid(&json!("_dmarc.example.com")));
assert!(!Name::new(Some(Record::TYPE_A)).is_valid(&json!("_dmarc.example.com")));
assert!(Caa.is_valid(&json!("0 issue \"letsencrypt.org\"")));
assert!(!Caa.is_valid(&json!("256 issue \"letsencrypt.org\"")));
```

## License

BSD-3-Clause. See [LICENSE](LICENSE).
