<?php

require __DIR__ . '/../../vendor/autoload.php';

use Utopia\DNS\Adapter\Swoole;
use Utopia\DNS\Message;
use Utopia\DNS\Message\Record;
use Utopia\DNS\Query;
use Utopia\DNS\Resolver;
use Utopia\DNS\Server;
use Utopia\DNS\Zone;
use Utopia\DNS\Zone\File;
use Utopia\DNS\Zone\Resolver as ZoneResolver;

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) {
    return;
}

$port = (int) (getenv('PORT') ?: 5300);
$httpPort = (int) (getenv('HTTP_PORT') ?: 5301);
$proxyPort = (int) (getenv('PROXY_PORT') ?: 5302);
$server = new Swoole([
    new Swoole\Udp('0.0.0.0', $port),
    new Swoole\Tcp('0.0.0.0', $port),
    new Swoole\Http('0.0.0.0', $httpPort),
    new Swoole\Tcp('0.0.0.0', $proxyPort, proxyProtocol: true),
]);

$records = [
    // Single A
    new Record(name: 'dev.appwrite.io', type: Record::TYPE_A, ttl: 10, rdata: '180.12.3.24'),
    // Mulple AAAA
    new Record(name: 'dev2.appwrite.io', type: Record::TYPE_A, ttl: 1800, rdata: '142.6.0.1'),
    new Record(name: 'dev2.appwrite.io', type: Record::TYPE_A, ttl: 1800, rdata: '142.6.0.2'),
    // Single AAAA
    new Record(name: 'dev.appwrite.io', type: Record::TYPE_AAAA, ttl: 20, rdata: '2001:0db8:0000:0000:0000:ff00:0042:8329'),
    // Multiple AAAA
    new Record(name: 'dev2.appwrite.io', type: Record::TYPE_AAAA, ttl: 20, rdata: '2001:0db8:0000:0000:0000:ff00:0000:0001'),
    new Record(name: 'dev2.appwrite.io', type: Record::TYPE_AAAA, ttl: 20, rdata: '2001:0db8:0000:0000:0000:ff00:0000:0002'),
    // Single CNAME
    new Record(name: 'alias.appwrite.io', type: Record::TYPE_CNAME, ttl: 30, rdata: 'cloud.appwrite.io'),
    // Secret TXT
    new Record(name: 'dev.appwrite.io', type: Record::TYPE_TXT, ttl: 30, rdata: 'awesome-secret-key'),
    // Mail MX
    new Record(name: 'dev.appwrite.io', type: Record::TYPE_MX, ttl: 30, rdata: '10 mail.appwrite.io'),
    // Single CAA
    new Record(name: 'dev.appwrite.io', type: Record::TYPE_CAA, ttl: 30, rdata: '0 issue "letsencrypt.org"'),
    // Subdomain NS delegation
    new Record(name: 'delegated.appwrite.io', type: Record::TYPE_NS, ttl: 30, rdata: 'ns1.test.io'),
    new Record(name: 'delegated.appwrite.io', type: Record::TYPE_NS, ttl: 30, rdata: 'ns2.test.io'),
];

$appwriteZone = new Zone(
    name: 'appwrite.io',
    records: $records,
    soa: new Record(
        name: 'appwrite.io',
        type: Record::TYPE_SOA,
        ttl: 30,
        rdata: 'ns1.appwrite.zone team.appwrite.io 1 7200 1800 1209600 3600',
    ),
);

// Load the localhost zone from zone file (contains large.localhost TXT records for TCP truncation tests)
$localhostZoneContent = (string) file_get_contents(__DIR__ . '/zone-valid-localhost.txt');
$localhostZone = File::import($localhostZoneContent);

/**
 * Simple multi-zone resolver for testing purposes
 */
$multiZoneResolver = new readonly class ([$appwriteZone, $localhostZone]) implements Resolver {
    /** @param list<Zone> $zones */
    public function __construct(private array $zones) {}

    public function resolve(Query $query): Message
    {
        $message = $query->message;
        $question = $message->questions[0] ?? null;
        if ($question === null) {
            return Message::response(
                header: $message->header,
                responseCode: Message::RCODE_FORMERR,
                authoritative: true,
            );
        }

        // Find the matching zone for this query
        $queryName = strtolower($question->name);
        foreach ($this->zones as $zone) {
            $zoneName = $zone->name;
            if ($queryName === $zoneName || str_ends_with($queryName, '.' . $zoneName)) {
                return ZoneResolver::lookup($message, $zone);
            }
        }

        // No matching zone found - return NXDOMAIN with first zone's SOA
        return Message::response(
            header: $message->header,
            responseCode: Message::RCODE_NXDOMAIN,
            questions: $message->questions,
            authority: [$this->zones[0]->soa],
            authoritative: true,
        );
    }
};

$dns = new Server($server, $multiZoneResolver);
$dns->setDebug(false);

$dns->start();
