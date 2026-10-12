A DNS server receives small binary packets, decodes a question, finds the records that answer it and sends an encoded response back, over UDP, TCP or HTTPS. This library covers each step: the message codec, zones and zone files, resolvers, a server on Tokio, a client, and validators for what users type into DNS settings. This guide covers the common tasks.

## Encode and decode messages

`Message::query` builds a query; `Message::decode` and `encode` convert to and from the wire format. Names and record data are bytes.

```
use utopia_dns::message::{Message, Question, Record};

let query = Message::query(Question::new("www.example.com", Record::TYPE_A), Some(0x1a2b), true);
let wire = query.encode(None)?;
assert_eq!(&wire[..2], &[0x1a, 0x2b]);

let decoded = Message::decode(&wire)?;
assert_eq!(decoded.questions()[0].name(), b"www.example.com");
assert_eq!(decoded.questions()[0].ty, Record::TYPE_A);
# Ok::<(), utopia_dns::Error>(())
```

```php
use Utopia\DNS\Message;
use Utopia\DNS\Message\Question;
use Utopia\DNS\Message\Record;

$query = Message::query(new Question('www.example.com', Record::TYPE_A), id: 0x1a2b);
$wire = $query->encode();
echo bin2hex(substr($wire, 0, 2)), "\n"; // 1a2b

$decoded = Message::decode($wire);
echo $decoded->questions[0]->name, "\n"; // www.example.com
echo $decoded->questions[0]->type, "\n"; // 1
```

## Answer from a zone

A `Zone` holds the records of a domain and its `SOA`. `zone::lookup` answers a query with exact matches, wildcards, `CNAME`s, delegations and negative answers.

```
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
# Ok::<(), utopia_dns::Error>(())
```

```php
use Utopia\DNS\Message;
use Utopia\DNS\Message\Question;
use Utopia\DNS\Message\Record;
use Utopia\DNS\Zone;
use Utopia\DNS\Zone\Resolver;

$zone = new Zone(
    'example.com',
    [new Record('*.example.com', Record::TYPE_A, ttl: 60, rdata: '192.0.2.1')],
    new Record('example.com', Record::TYPE_SOA, ttl: 3600, rdata: 'ns1.example.com hostmaster.example.com 1 7200 3600 1209600 300'),
);

$answer = Resolver::lookup(Message::query(new Question('app.example.com', Record::TYPE_A), id: 1), $zone);
echo $answer->answers[0]->name, ' ', $answer->answers[0]->rdata, "\n"; // app.example.com 192.0.2.1

$missing = Resolver::lookup(Message::query(new Question('example.org', Record::TYPE_A), id: 2), $zone);
echo $missing->header->responseCode, "\n"; // 3
```

## Import a zone file

`zone::import` reads the master file format; `zone::export` writes it back.

```
use utopia_dns::zone;

let zone = zone::import(b"$ORIGIN example.com.
@ IN SOA ns1 admin 2025011801 7200 3600 1209600 1800
www 300 IN A 192.0.2.10
mail IN MX 10 www", None, 3600)?;

assert_eq!(zone.records().len(), 2);
assert_eq!(zone.records()[1].rdata(), b"www.example.com");
let file = zone::export(&zone, false);
assert!(file.starts_with(b"$ORIGIN example.com.\n$TTL 3600\n"));
# Ok::<(), utopia_dns::Error>(())
```

```php
use Utopia\DNS\Zone\File;

$zone = File::import(<<<'ZONE'
$ORIGIN example.com.
@ IN SOA ns1 admin 2025011801 7200 3600 1209600 1800
www 300 IN A 192.0.2.10
mail IN MX 10 www
ZONE);

echo count($zone->records), "\n";     // 2
echo $zone->records[1]->rdata, "\n";  // www.example.com
echo strtok(File::export($zone, includeComments: false), "\n"), "\n"; // $ORIGIN example.com.
```

## Serve

A `Server` answers with a resolver; the Tokio adapter listens on UDP, TCP and DNS over HTTPS. `Handler::handle` is what the adapter calls for each packet, so a server can be tried without sockets.

```
use utopia_dns::message::{Message, Question, Record};
use utopia_dns::resolver::Memory;
use utopia_dns::{Handler, Protocol, Server, Zone};

# tokio::runtime::Builder::new_current_thread().build().unwrap().block_on(async {
let zone = Zone::new(
    "example.test",
    vec![Record::new("www.example.test", Record::TYPE_TXT).with_ttl(60).with_rdata("hello")],
    Record::new("example.test", Record::TYPE_SOA).with_ttl(60).with_rdata("ns1 hostmaster 1 7200 1800 1209600 3600"),
)?;
let server = Server::new(Memory::new(zone));

let query = Message::query(Question::new("www.example.test", Record::TYPE_TXT), Some(9), true);
let packet = server.handle(&query.encode(None)?, "192.0.2.7", 53000, Protocol::Udp).await;
assert_eq!(Message::decode(&packet)?.answers()[0].rdata(), b"hello");
# Ok::<(), utopia_dns::Error>(())
# }).unwrap();
```

```php
use Utopia\DNS\Message;
use Utopia\DNS\Message\Question;
use Utopia\DNS\Message\Record;
use Utopia\DNS\Protocol;
use Utopia\DNS\Query;
use Utopia\DNS\Resolver\Memory;
use Utopia\DNS\Zone;

$zone = new Zone(
    'example.test',
    [new Record('www.example.test', Record::TYPE_TXT, ttl: 60, rdata: 'hello')],
    new Record('example.test', Record::TYPE_SOA, ttl: 60, rdata: 'ns1 hostmaster 1 7200 1800 1209600 3600'),
);
$query = Message::query(new Question('www.example.test', Record::TYPE_TXT), id: 9);
$response = (new Memory($zone))->resolve(new Query($query, '192.0.2.7', 53000, Protocol::Udp));
echo $response->answers[0]->rdata, "\n"; // hello
```

## Validate user input

`validator::Name` checks record names (underscores are fine except for `A`/`AAAA` owners) and `validator::Caa` checks CAA values.

```
use serde_json::json;
use utopia_dns::message::Record;
use utopia_dns::validator::{Caa, Name};
use utopia_validators::Validator;

assert!(Name::new(Some(Record::TYPE_TXT)).is_valid(&json!("_dmarc.example.com")));
assert!(!Name::new(Some(Record::TYPE_A)).is_valid(&json!("_dmarc.example.com")));
assert!(Caa.is_valid(&json!("0 issue \"letsencrypt.org\"")));
assert!(!Caa.is_valid(&json!("256 issue \"letsencrypt.org\"")));
```

```php
use Utopia\DNS\Message\Record;
use Utopia\DNS\Validator\CAA;
use Utopia\DNS\Validator\Name;

var_dump((new Name(Record::TYPE_TXT))->isValid('_dmarc.example.com')); // bool(true)
var_dump((new Name(Record::TYPE_A))->isValid('_dmarc.example.com'));   // bool(false)
var_dump((new CAA())->isValid('0 issue "letsencrypt.org"'));            // bool(true)
var_dump((new CAA())->isValid('256 issue "letsencrypt.org"'));          // bool(false)
```
