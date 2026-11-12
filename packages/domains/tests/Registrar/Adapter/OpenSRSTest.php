<?php

declare(strict_types=1);

namespace Utopia\Domains\Tests\Registrar\Adapter;

use PHPUnit\Framework\TestCase;
use SimpleXMLElement;
use Utopia\Domains\Exception as DomainsException;
use Utopia\Domains\Registrar\Adapter\OpenSRS;
use Utopia\Domains\Registrar\Contact;
use Utopia\Domains\Registrar\UpdateDetails;

/**
 * The envelope is signed with the reseller key after it is built, so every
 * caller value must stay inside its own element. These run against a fake
 * transport and never reach OpenSRS.
 */
final class OpenSRSTest extends TestCase
{
    private const string KEY = 'test-key';

    private const string RESPONSE = '<?xml version="1.0" encoding="UTF-8" standalone="no"?><!DOCTYPE OPS_envelope SYSTEM "ops.dtd"><OPS_envelope><header><version>0.9</version></header><body><data_block><dt_assoc><item key="response_code">200</item><item key="is_success">1</item><item key="response_text">Command successful</item><item key="attributes"><dt_assoc><item key="id">123456</item><item key="details"><dt_assoc><item key="example.net"><dt_assoc><item key="is_success">0</item></dt_assoc></item></dt_assoc></item></dt_assoc></item></dt_assoc></data_block></body></OPS_envelope>';

    /**
     * Captured from the builder before escaping was added: ordinary input must
     * keep producing these exact bytes, or the live integration has changed.
     */
    private const string PLAIN_ENVELOPE = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<!DOCTYPE OPS_envelope SYSTEM 'ops.dtd'>
<OPS_envelope>
<header>
<version>0.9</version>
</header>
<body>
<data_block>
<dt_assoc>
<item key='protocol'>XCP</item>
<item key='object'>DOMAIN</item>
<item key='action'>SW_REGISTER</item>

<item key="attributes">
<dt_assoc>
<item key='domain'>example.net</item>
<item key='periodYears'>1</item>
<item key="contact_set">
<dt_assoc>
<item key='owner'>
<dt_assoc>
<item key='first_name'>Test</item>
<item key='last_name'>Tester</item>
<item key='email'>testing@test.com</item>
<item key='phone'>+18031234567</item>
<item key='address1'>123 Main St</item>
<item key='city'>San Francisco</item>
<item key='state'>CA</item>
<item key='postal_code'>94105</item>
<item key='country'>US</item>
<item key='owner'>Test Tester</item>
<item key='org_name'>Test Inc</item>
</dt_assoc>
</item>
<item key='admin'>
<dt_assoc>
<item key='first_name'>Test</item>
<item key='last_name'>Tester</item>
<item key='email'>testing@test.com</item>
<item key='phone'>+18031234567</item>
<item key='address1'>123 Main St</item>
<item key='city'>San Francisco</item>
<item key='state'>CA</item>
<item key='postal_code'>94105</item>
<item key='country'>US</item>
<item key='owner'>Test Tester</item>
<item key='org_name'>Test Inc</item>
</dt_assoc>
</item>
<item key='tech'>
<dt_assoc>
<item key='first_name'>Test</item>
<item key='last_name'>Tester</item>
<item key='email'>testing@test.com</item>
<item key='phone'>+18031234567</item>
<item key='address1'>123 Main St</item>
<item key='city'>San Francisco</item>
<item key='state'>CA</item>
<item key='postal_code'>94105</item>
<item key='country'>US</item>
<item key='owner'>Test Tester</item>
<item key='org_name'>Test Inc</item>
</dt_assoc>
</item>
<item key='billing'>
<dt_assoc>
<item key='first_name'>Test</item>
<item key='last_name'>Tester</item>
<item key='email'>testing@test.com</item>
<item key='phone'>+18031234567</item>
<item key='address1'>123 Main St</item>
<item key='city'>San Francisco</item>
<item key='state'>CA</item>
<item key='postal_code'>94105</item>
<item key='country'>US</item>
<item key='owner'>Test Tester</item>
<item key='org_name'>Test Inc</item>
</dt_assoc>
</item>
</dt_assoc>
</item>
<item key='custom_tech_contact'>0</item>
<item key='custom_nameservers'>1</item>
<item key='reg_username'>test-user</item>
<item key='reg_password'>test-pass</item>
<item key='reg_type'>new</item>
<item key='handle'>process</item>
<item key='f_whois_privacy'>1</item>
<item key='auto_renew'>0</item>
<item key="nameserver_list">
<dt_array>
<dt_assoc>
<item key='name'>ns1.systemdns.com</item>
<item key='sortorder'>0</item>
</dt_assoc>
<dt_assoc>
<item key='name'>ns2.systemdns.com</item>
<item key='sortorder'>1</item>
</dt_assoc>
</dt_array>
</item>
</dt_assoc>
</item>
</dt_assoc>
</data_block>
</body>
</OPS_envelope>
XML;

    public function testPurchaseEnvelopeIsUnchanged(): void
    {
        $client = new Client(self::RESPONSE);

        $this->adapter($client)->purchase('example.net', $this->contacts(), 1, ['ns1.systemdns.com', 'ns2.systemdns.com']);

        $this->assertSame(self::PLAIN_ENVELOPE, (string) $client->last()->getBody());
    }

    public function testPurchaseEscapesContact(): void
    {
        $client = new Client(self::RESPONSE);
        $lastName = 'O\'Brien & "Sons" <item key=\'auto_renew\'>1</item>';

        $this->adapter($client)->purchase('example.net', $this->contacts($lastName), 1, ['ns1.systemdns.com'], false);

        $body = (string) $client->last()->getBody();
        $this->assertStringNotContainsString("<item key='auto_renew'>1</item>", $body);

        $xml = $this->parse($body);
        $autoRenew = $this->nodes($xml, '//item[@key="auto_renew"]');
        $this->assertCount(1, $autoRenew, 'Contact text added items of its own');
        $this->assertSame('0', (string) $autoRenew[0]);
        $this->assertCount(0, $this->nodes($xml, '//item[@key="contact_set"]//item[@key="auto_renew"]'));

        $stored = $this->nodes($xml, '//item[@key="contact_set"]/dt_assoc/item[@key="owner"]/dt_assoc/item[@key="last_name"]');
        $this->assertCount(1, $stored);
        $this->assertSame($lastName, (string) $stored[0]);
    }

    public function testUpdateNameserversEscapesMarkup(): void
    {
        $client = new Client(self::RESPONSE);
        $injected = "evil.example</item><item key='1'>injected";

        $this->adapter($client)->updateNameservers('example.net', ['ns1.example.com', $injected]);

        $items = $this->nodes($this->parse((string) $client->last()->getBody()), '//item[@key="add_ns"]/dt_array/item');
        $this->assertCount(2, $items);
        $this->assertSame($injected, (string) $items[1]);
    }

    public function testPurchaseRejectsUnknownContactType(): void
    {
        $client = new Client(self::RESPONSE);
        $contacts = $this->contacts();
        $contacts["billing' x='"] = $contacts['billing'];

        try {
            $this->adapter($client)->purchase('example.net', $contacts);
            $this->fail('A contact type that is not owner, admin, tech or billing was accepted');
        } catch (DomainsException $e) {
            $this->assertStringContainsString('Unknown contact type', $e->getMessage());
        }

        $this->assertSame([], $client->requests, 'Nothing may be sent for a rejected contact set');
    }

    public function testSuggestEscapesKeys(): void
    {
        $client = new Client(self::RESPONSE);

        $this->adapter($client)->suggest('x', ["a'b" => 'com']);

        $items = $this->nodes($this->parse((string) $client->last()->getBody()), '//item[@key="tlds"]/dt_array/item');
        $this->assertNotEmpty($items);
        $this->assertSame("a'b", (string) $items[0]['key']);
        $this->assertSame('.com', (string) $items[0]);
    }

    public function testSignatureCoversTheEscapedBody(): void
    {
        $client = new Client(self::RESPONSE);

        $this->adapter($client)->purchase('example.net', $this->contacts('Smith & Sons'));

        $request = $client->last();
        $body = (string) $request->getBody();
        $this->assertSame(md5(md5($body . self::KEY) . self::KEY), $request->getHeaderLine('X-Signature'));
        $this->assertSame('test-user', $request->getHeaderLine('X-Username'));
    }

    public function testUpdateDomainReadsTheDomainVerdict(): void
    {
        $adapter = $this->adapter(new Client(self::RESPONSE));

        // The per-domain verdict (0) wins over the top-level is_success (1).
        $this->assertFalse($adapter->updateDomain('example.net', new UpdateDetails(true)));
        // A domain that would have broken the old XPath simply has no entry.
        $this->assertTrue($adapter->updateDomain('evil"]', new UpdateDetails(true)));
    }

    private function adapter(Client $client): OpenSRS
    {
        return new OpenSRS(self::KEY, 'test-user', 'test-pass', 'https://horizon.opensrs.net:55443', $client);
    }

    /**
     * @return array<string, Contact>
     */
    private function contacts(string $lastName = 'Tester'): array
    {
        $contact = new Contact('Test', $lastName, '+18031234567', 'testing@test.com', '123 Main St', 'Suite 100', '', 'San Francisco', 'CA', 'US', '94105', 'Test Inc');

        return ['owner' => $contact, 'admin' => $contact, 'tech' => $contact, 'billing' => $contact];
    }

    private function parse(string $body): SimpleXMLElement
    {
        $xml = simplexml_load_string($body);
        $this->assertInstanceOf(SimpleXMLElement::class, $xml, 'Envelope is not well-formed XML');

        return $xml;
    }

    /**
     * @return list<SimpleXMLElement>
     */
    private function nodes(SimpleXMLElement $xml, string $xpath): array
    {
        $nodes = $xml->xpath($xpath);
        $this->assertIsArray($nodes);

        return array_values($nodes);
    }
}
