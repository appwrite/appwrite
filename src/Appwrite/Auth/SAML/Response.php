<?php

namespace Appwrite\Auth\SAML;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use RobRichards\XMLSecLibs\XMLSecurityDSig;

/**
 * Parses and validates a SAML 2.0 `<Response>` posted to the Assertion
 * Consumer Service.
 *
 * Everything in an incoming response is attacker controlled until the
 * signature over it has been verified, so validate() runs in a fixed order and
 * no claim is read before the element carrying it is known to be signed:
 *
 *   1. Parse with entity loading disabled (XXE).
 *   2. Verify the signature against the configured IdP certificate.
 *   3. Confirm the signature actually covers the assertion we read (wrapping).
 *   4. Check status, conditions, audience, recipient and InResponseTo.
 *   5. Only then extract NameID and attributes.
 *
 * Step 3 is the one that is easy to get wrong: a valid signature over *some*
 * element in the document says nothing about the element the claims are read
 * from. Signature wrapping exploits exactly that gap.
 */
class Response
{
    private const string NS_PROTOCOL = 'urn:oasis:names:tc:SAML:2.0:protocol';
    private const string NS_ASSERTION = 'urn:oasis:names:tc:SAML:2.0:assertion';
    private const string NS_DSIG = 'http://www.w3.org/2000/09/xmldsig#';

    private const string STATUS_SUCCESS = 'urn:oasis:names:tc:SAML:2.0:status:Success';

    /**
     * The only subject confirmation method a service provider can honour on its
     * own: possession of the assertion is the proof. holder-of-key and
     * sender-vouches both require the presenter to demonstrate something
     * further.
     */
    private const string CONFIRMATION_BEARER = 'urn:oasis:names:tc:SAML:2.0:cm:bearer';

    /**
     * Tolerance applied to NotBefore/NotOnOrAfter, in seconds, to absorb clock
     * drift between us and the IdP.
     */
    private const int CLOCK_SKEW = 60;

    /**
     * @var DOMDocument
     */
    private DOMDocument $document;

    /**
     * @var DOMXPath
     */
    private DOMXPath $xpath;

    /**
     * @var DOMElement
     */
    private DOMElement $assertion;

    /**
     * @param Settings $settings
     * @param string $samlResponse The base64 `SAMLResponse` form field.
     *
     * @throws Exception when the payload is not decodable, well-formed XML.
     */
    public function __construct(private readonly Settings $settings, string $samlResponse)
    {
        $xml = \base64_decode($samlResponse, true);

        if ($xml === false || \trim($xml) === '') {
            throw new Exception('SAML response is not valid base64.');
        }

        $this->document = self::parse($xml);
        $this->xpath = new DOMXPath($this->document);
        $this->xpath->registerNamespace('samlp', self::NS_PROTOCOL);
        $this->xpath->registerNamespace('saml', self::NS_ASSERTION);
        $this->xpath->registerNamespace('ds', self::NS_DSIG);
    }

    /**
     * Parse untrusted XML with external entity resolution disabled and DOCTYPE
     * rejected outright, closing XXE and billion-laughs.
     *
     * LIBXML_NONET blocks network access and LIBXML_NOENT is deliberately not
     * passed (it *enables* entity substitution despite the name). The DOCTYPE
     * check is belt-and-braces: no legitimate SAML response has one.
     *
     * @param string $xml
     *
     * @return DOMDocument
     */
    private static function parse(string $xml): DOMDocument
    {
        $document = new DOMDocument();
        $document->preserveWhiteSpace = true;
        $document->formatOutput = false;

        $previous = \libxml_use_internal_errors(true);

        try {
            $loaded = $document->loadXML($xml, LIBXML_NONET);
        } finally {
            \libxml_clear_errors();
            \libxml_use_internal_errors($previous);
        }

        if ($loaded === false) {
            throw new Exception('SAML response is not well-formed XML.');
        }

        foreach ($document->childNodes as $child) {
            if ($child->nodeType === XML_DOCUMENT_TYPE_NODE) {
                throw new Exception('SAML response must not contain a document type declaration.');
            }
        }

        return $document;
    }

    /**
     * First element matching an XPath expression, or null when there is none.
     *
     * DOMXPath::query() is typed as returning DOMNode, which has no attribute
     * accessors, so narrowing to DOMElement here keeps every call site both
     * type-safe and free of repeated null handling.
     *
     * @param string $expression
     * @param DOMNode|null $context
     *
     * @return DOMElement|null
     */
    private function element(string $expression, ?DOMNode $context = null): ?DOMElement
    {
        $nodes = $context === null
            ? $this->xpath->query($expression)
            : $this->xpath->query($expression, $context);

        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        $node = $nodes->item(0);

        return $node instanceof DOMElement ? $node : null;
    }

    /**
     * Run the full validation chain.
     *
     * @param string|null $expectedInResponseTo ID of the AuthnRequest we issued.
     *
     * @return void
     *
     * @throws Exception on any validation failure.
     */
    public function validate(?string $expectedInResponseTo): void
    {
        $this->assertSingleResponseAndAssertion();
        $this->verifySignature();
        $this->assertStatusSuccess();
        $this->assertIssuer();
        $this->assertConditions();
        $this->assertSubjectConfirmation($expectedInResponseTo);
    }

    /**
     * Reject documents carrying more than one `<Response>` or `<Assertion>`.
     *
     * Signature wrapping usually works by adding a second, unsigned element
     * that the consumer reads while the signature still validates over the
     * original. Insisting on exactly one of each removes that entire class of
     * payload before any signature work happens.
     *
     * @return void
     */
    private function assertSingleResponseAndAssertion(): void
    {
        $responses = $this->xpath->query('//samlp:Response');

        if ($responses === false || $responses->length !== 1) {
            throw new Exception('SAML response must contain exactly one Response element.');
        }

        if ($responses->item(0) !== $this->document->documentElement) {
            throw new Exception('SAML Response element must be the document root.');
        }

        $assertions = $this->xpath->query('//saml:Assertion');

        if ($assertions === false || $assertions->length !== 1) {
            throw new Exception('SAML response must contain exactly one Assertion element.');
        }

        $assertion = $assertions->item(0);

        if (!$assertion instanceof DOMElement) {
            throw new Exception('SAML assertion is malformed.');
        }

        // The assertion must be a direct child of the Response, not buried in
        // an Extensions element or some other wrapper.
        if ($assertion->parentNode !== $this->document->documentElement) {
            throw new Exception('SAML assertion must be a direct child of the Response element.');
        }

        $this->assertion = $assertion;
    }

    /**
     * Verify the XML signature against the configured IdP certificate.
     *
     * Two properties matter beyond "the signature verifies":
     *
     *  - The key is the one the admin configured. A certificate embedded in the
     *    response is never trusted; otherwise anyone could sign their own
     *    assertion and ship the matching certificate alongside it.
     *  - The signed element is the Response root or the assertion we read from.
     *    Verified by resolving the Reference URI back to a specific element.
     *
     * @return void
     */
    private function verifySignature(): void
    {
        $signatures = $this->xpath->query('//ds:Signature');

        if ($signatures === false || $signatures->length === 0) {
            throw new Exception('SAML response is not signed.');
        }

        // Identity providers commonly sign both the Response and the Assertion
        // (Okta does so by default), so more than one signature is normal and
        // is not itself a problem. What matters is that the signature we verify
        // is attached to the Response root or to the assertion the claims are
        // read from; a signature over any other element proves nothing.
        //
        // The assertion signature is preferred when both are present, because
        // it is the assertion that carries the identity.
        /** @var DOMElement|null $signatureNode */
        $signatureNode = null;
        $signedElement = null;

        foreach ($signatures as $candidate) {
            if (!$candidate instanceof DOMElement) {
                continue;
            }

            $parent = $candidate->parentNode;

            if ($parent === $this->assertion) {
                $signatureNode = $candidate;
                $signedElement = $parent;

                break;
            }

            if ($parent === $this->document->documentElement && $signatureNode === null) {
                $signatureNode = $candidate;
                $signedElement = $parent;
            }
        }

        if ($signatureNode === null || !$signedElement instanceof DOMElement) {
            throw new Exception('SAML signature must cover the Response or the Assertion.');
        }

        // The reference has to be read now: validateReference() detaches the
        // Signature node from the document to apply the enveloped-signature
        // transform, so nothing under it is queryable afterwards.
        $referenceUri = $this->readReferenceUri($signatureNode);
        $signedElementId = $signedElement->getAttribute('ID');

        $dsig = new XMLSecurityDSig();
        // SAML IDs are plain `ID` attributes rather than xml:id, so xmlseclibs
        // has to be told how to resolve `URI="#..."` references.
        $dsig->idKeys[] = 'ID';

        // Point xmlseclibs at the signature chosen above rather than letting it
        // locate one itself, which would pick the first in document order and
        // may not be the one covering the assertion.
        $dsig->sigNode = $signatureNode;

        try {
            $dsig->canonicalizeSignedInfo();

            if (!$dsig->validateReference()) {
                throw new Exception('SAML signature reference validation failed.');
            }
        } catch (Exception $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new Exception('SAML signature reference validation failed.', previous: $error);
        }

        $this->assertReferenceCovers($referenceUri, $signedElementId, $signedElement);

        $key = $dsig->locateKey();

        if ($key === null) {
            throw new Exception('SAML signature uses an unsupported key type.');
        }

        // Load the configured certificate rather than anything the response
        // carried with it.
        try {
            $key->loadKey($this->settings->getX509Cert(), false, true);
        } catch (\Throwable $error) {
            throw new Exception('Configured SAML signing certificate could not be loaded.', previous: $error);
        }

        try {
            $verified = $dsig->verify($key) === 1;
        } catch (\Throwable $error) {
            throw new Exception('SAML signature verification failed.', previous: $error);
        }

        if (!$verified) {
            throw new Exception('SAML signature verification failed. Check that the configured certificate matches the identity provider signing certificate.');
        }
    }

    /**
     * Read the single Reference URI out of a signature, before xmlseclibs gets
     * a chance to detach it from the document.
     *
     * @param DOMNode $signatureNode
     *
     * @return string
     */
    private function readReferenceUri(DOMNode $signatureNode): string
    {
        $references = $this->xpath->query('.//ds:SignedInfo/ds:Reference', $signatureNode);

        if ($references === false || $references->length !== 1) {
            throw new Exception('SAML signature must contain exactly one reference.');
        }

        $reference = $references->item(0);

        if (!$reference instanceof DOMElement) {
            throw new Exception('SAML signature reference is malformed.');
        }

        return $reference->getAttribute('URI');
    }

    /**
     * Confirm the verified Reference actually points at the element the
     * signature is attached to.
     *
     * validateReference() proves the digest matches whatever the Reference URI
     * resolved to; it does not prove that node is the one we care about. Tying
     * the URI back to the signed element's own ID closes the gap.
     *
     * @param string $uri
     * @param string $signedElementId
     * @param DOMElement $signedElement
     *
     * @return void
     */
    private function assertReferenceCovers(string $uri, string $signedElementId, DOMElement $signedElement): void
    {
        // An empty URI signs the whole document, which is only meaningful on
        // the root element.
        if ($uri === '') {
            if ($signedElement !== $this->document->documentElement) {
                throw new Exception('SAML signature reference does not cover the signed element.');
            }

            return;
        }

        if (!\str_starts_with($uri, '#')) {
            throw new Exception('SAML signature reference must be a same-document reference.');
        }

        $referenced = \substr($uri, 1);

        if ($signedElementId === '' || !\hash_equals($signedElementId, $referenced)) {
            throw new Exception('SAML signature reference does not cover the signed element.');
        }
    }

    /**
     * @return void
     */
    private function assertStatusSuccess(): void
    {
        $status = $this->element('/samlp:Response/samlp:Status/samlp:StatusCode');

        if ($status === null) {
            throw new Exception('SAML response is missing a status code.');
        }

        $code = $status->getAttribute('Value');

        if ($code !== self::STATUS_SUCCESS) {
            // The status code is IdP-authored and signed, so it is safe to
            // surface; it is the most useful thing an admin can act on.
            throw new Exception('SAML identity provider rejected the sign-in request: ' . $code);
        }
    }

    /**
     * @return void
     */
    private function assertIssuer(): void
    {
        $issuerNode = $this->element('/samlp:Response/saml:Assertion/saml:Issuer');

        if ($issuerNode === null) {
            throw new Exception('SAML assertion is missing an issuer.');
        }

        $issuer = \trim($issuerNode->textContent);

        if (!\hash_equals($this->settings->getIdpEntityId(), $issuer)) {
            throw new Exception('SAML assertion issuer does not match the configured identity provider entity ID.');
        }
    }

    /**
     * Enforce the validity window and the audience restriction.
     *
     * @return void
     */
    private function assertConditions(): void
    {
        $condition = $this->element('/samlp:Response/saml:Assertion/saml:Conditions');

        if ($condition === null) {
            throw new Exception('SAML assertion is missing conditions.');
        }

        $now = \time();

        $notBefore = $condition->getAttribute('NotBefore');

        if ($notBefore !== '' && $now + self::CLOCK_SKEW < self::timestamp($notBefore)) {
            throw new Exception('SAML assertion is not yet valid.');
        }

        $notOnOrAfter = $condition->getAttribute('NotOnOrAfter');

        if ($notOnOrAfter !== '' && $now - self::CLOCK_SKEW >= self::timestamp($notOnOrAfter)) {
            throw new Exception('SAML assertion has expired.');
        }

        $audiences = $this->xpath->query('.//saml:AudienceRestriction/saml:Audience', $condition);

        if ($audiences === false || $audiences->length === 0) {
            throw new Exception('SAML assertion is missing an audience restriction.');
        }

        foreach ($audiences as $audience) {
            if (\hash_equals($this->settings->getSpEntityId(), \trim($audience->textContent))) {
                return;
            }
        }

        throw new Exception('SAML assertion audience does not match the configured service provider entity ID.');
    }

    /**
     * Require a fully constrained bearer SubjectConfirmation.
     *
     * The bearer method is the only one this service provider can honour: it
     * means possession of the assertion is proof of identity. `holder-of-key`
     * and `sender-vouches` both require the presenter to demonstrate something
     * further, and treating them as bearer would accept an assertion that was
     * never meant to authenticate whoever delivered it.
     *
     * The constraints on a bearer confirmation are what stop a captured
     * assertion being useful elsewhere or later, so Recipient, NotOnOrAfter and
     * InResponseTo are all mandatory rather than checked only when present.
     *
     * An assertion may carry several SubjectConfirmation elements; the subject
     * is confirmed if any one of them is satisfied, so each is tried in turn
     * and the reason from the last failure is reported.
     *
     * @param string|null $expectedInResponseTo
     *
     * @return void
     */
    private function assertSubjectConfirmation(?string $expectedInResponseTo): void
    {
        // Unsolicited (IdP-initiated) responses are out of scope. Without a
        // request of our own there is nothing to bind the response to, so a
        // captured assertion could be replayed into any browser session.
        if ($expectedInResponseTo === null) {
            throw new Exception('Unsolicited SAML responses are not supported. Start the sign-in from Appwrite rather than from the identity provider.');
        }

        $confirmations = $this->xpath->query('/samlp:Response/saml:Assertion/saml:Subject/saml:SubjectConfirmation');

        if ($confirmations === false || $confirmations->length === 0) {
            throw new Exception('SAML assertion is missing subject confirmation.');
        }

        $failure = null;

        foreach ($confirmations as $confirmation) {
            if (!$confirmation instanceof DOMElement) {
                continue;
            }

            try {
                $this->assertBearerConfirmation($confirmation, $expectedInResponseTo);

                return;
            } catch (Exception $error) {
                $failure = $error;
            }
        }

        throw $failure ?? new Exception('SAML assertion has no usable subject confirmation.');
    }

    /**
     * Check one SubjectConfirmation against the bearer requirements.
     *
     * @param DOMElement $confirmation
     * @param string $expectedInResponseTo
     *
     * @return void
     */
    private function assertBearerConfirmation(DOMElement $confirmation, string $expectedInResponseTo): void
    {
        if ($confirmation->getAttribute('Method') !== self::CONFIRMATION_BEARER) {
            throw new Exception('SAML assertion subject confirmation must use the bearer method.');
        }

        $data = $this->element('./saml:SubjectConfirmationData', $confirmation);

        if ($data === null) {
            throw new Exception('SAML assertion is missing subject confirmation data.');
        }

        $recipient = $data->getAttribute('Recipient');

        if ($recipient === '') {
            throw new Exception('SAML assertion subject confirmation is missing a recipient.');
        }

        if (!\hash_equals($this->settings->getAcsUrl(), $recipient)) {
            throw new Exception('SAML assertion recipient does not match the assertion consumer service URL.');
        }

        $notOnOrAfter = $data->getAttribute('NotOnOrAfter');

        if ($notOnOrAfter === '') {
            throw new Exception('SAML assertion subject confirmation is missing an expiry.');
        }

        if (\time() - self::CLOCK_SKEW >= self::timestamp($notOnOrAfter)) {
            throw new Exception('SAML assertion has expired.');
        }

        $notBefore = $data->getAttribute('NotBefore');

        // NotBefore is not permitted on a bearer confirmation at all.
        if ($notBefore !== '' && \time() + self::CLOCK_SKEW < self::timestamp($notBefore)) {
            throw new Exception('SAML assertion is not yet valid.');
        }

        $inResponseTo = $data->getAttribute('InResponseTo');

        if ($inResponseTo === '' || !\hash_equals($expectedInResponseTo, $inResponseTo)) {
            throw new Exception('SAML assertion does not correspond to the authentication request that started this sign-in.');
        }
    }

    /**
     * Parse an xsd:dateTime into a unix timestamp.
     *
     * @param string $value
     *
     * @return int
     */
    private static function timestamp(string $value): int
    {
        $parsed = \strtotime($value);

        if ($parsed === false) {
            throw new Exception('SAML assertion contains an invalid timestamp.');
        }

        return $parsed;
    }

    /**
     * Assertion ID, used to reject replayed assertions.
     *
     * @return string
     */
    public function getAssertionId(): string
    {
        return $this->assertion->getAttribute('ID');
    }

    /**
     * Point in time after which this assertion is worthless, used as the TTL of
     * the replay-prevention record. Falls back to a short window when the IdP
     * omits the attribute.
     *
     * @return int
     */
    public function getExpiry(): int
    {
        $conditions = $this->element('/samlp:Response/saml:Assertion/saml:Conditions');
        $notOnOrAfter = $conditions?->getAttribute('NotOnOrAfter') ?? '';

        if ($notOnOrAfter === '') {
            return \time() + 300;
        }

        return self::timestamp($notOnOrAfter);
    }

    /**
     * @return string
     */
    public function getNameId(): string
    {
        $nameId = $this->element('/samlp:Response/saml:Assertion/saml:Subject/saml:NameID');

        return $nameId === null ? '' : \trim($nameId->textContent);
    }

    /**
     * Attribute statements, flattened to name to list of values.
     *
     * @return array<string, array<int, string>>
     */
    public function getAttributes(): array
    {
        $attributes = [];
        $nodes = $this->xpath->query('/samlp:Response/saml:Assertion/saml:AttributeStatement/saml:Attribute');

        if ($nodes === false) {
            return $attributes;
        }

        foreach ($nodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }

            $name = $node->getAttribute('Name');

            if ($name === '') {
                continue;
            }

            $values = [];
            $valueNodes = $this->xpath->query('./saml:AttributeValue', $node);

            if ($valueNodes !== false) {
                foreach ($valueNodes as $valueNode) {
                    $values[] = \trim($valueNode->textContent);
                }
            }

            $attributes[$name] = $values;
        }

        return $attributes;
    }
}
