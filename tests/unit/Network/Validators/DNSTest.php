<?php

declare(strict_types=1);

namespace Tests\Unit\Network\Validators;

use Appwrite\Network\Validator\DNS;
use PHPUnit\Framework\TestCase;
use Utopia\DNS\Message\Record;
use Utopia\DNS\Validator\DNS as BaseDNS;

final class DNSTest extends TestCase
{
    public function testSingleDNSServer(): void
    {
        $validator = new DNS('appwrite.io', Record::TYPE_CNAME, ['8.8.8.8']);

        $this->assertEquals(false, $validator->isValid(''));
        $this->assertEquals(false, $validator->isValid(null));
        $this->assertSame('string', $validator->getType());
    }

    public function testMultipleDNSServers(): void
    {
        $validator = new DNS('appwrite.io', Record::TYPE_CNAME, ['8.8.8.8', '1.1.1.1']);

        $this->assertEquals(false, $validator->isValid(''));
        $this->assertEquals(false, $validator->isValid(null));
        $this->assertSame('string', $validator->getType());
    }

    public function testNoResolverValidatesNothing(): void
    {
        // A fan-out over zero resolvers queries nothing, so it has proven nothing.
        // Reporting success there would verify every domain it is ever asked about.
        $validator = new DNS('appwrite.io', Record::TYPE_CNAME, []);

        $this->assertFalse($validator->isValid('appwrite.io'));
        $this->assertNotEmpty($validator->getDescription());
    }

    public function testLookupErrorOutranksNoRecordAcrossResolvers(): void
    {
        // One resolver answered NODATA; another could not answer at all. The
        // fan-out used to keep whichever finished last, so a NODATA reply could
        // wipe the error and look like "no proof" to resolveOwnership().
        $nodata = new BaseDNS('target.example', Record::TYPE_TXT, '1.1.1.1');
        $nodata->reason = '';
        $nodata->count = 0;

        $error = new BaseDNS('target.example', Record::TYPE_TXT, '8.8.8.8');
        $error->reason = 'DNS TXT query to 8.8.8.8 failed.';
        $error->count = 0;

        $failed = null;
        DNS::retainFailure($nodata, $failed);
        DNS::retainFailure($error, $failed);

        $this->assertSame($error, $failed);

        $failed = null;
        DNS::retainFailure($error, $failed);
        DNS::retainFailure($nodata, $failed);

        $this->assertSame($error, $failed);
    }

    public function testValidationFailure(): void
    {
        $validator = new DNS('invalid-target.example.com', Record::TYPE_CNAME, ['8.8.8.8', '1.1.1.1']);

        $result = $validator->isValid('nonexistent-domain-' . \uniqid() . '.com');

        $this->assertEquals(false, $result);
        $this->assertNotEmpty($validator->getDescription());
    }

    public function testFixtureResolverFailure(): void
    {
        // The test resolver (tests/resources/dns) returns cname.localhost. for stage.webapp.com
        $validator = new DNS('cname.localhost.', Record::TYPE_CNAME, ['172.16.238.100', '8.8.8.8']);

        $result = $validator->isValid('stage.webapp.com');
        $this->assertEquals(false, $result);

        $result = $validator->isValid('stage-wrong-cname.webapp.com');
        $this->assertEquals(false, $result);
    }
}
