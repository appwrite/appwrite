<?php

namespace Appwrite\Auth\SAML;

use DOMDocument;

/**
 * Generates the SP metadata document an IdP administrator imports to configure
 * the trust relationship from their side.
 *
 * Publishing metadata is preferable to asking admins to copy individual fields:
 * every IdP worth integrating can consume it, and it stays correct if the SP
 * surface changes.
 */
class Metadata
{
    private const string NS_METADATA = 'urn:oasis:names:tc:SAML:2.0:metadata';
    private const string BINDING_POST = 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST';

    /**
     * @param Settings $settings
     */
    public function __construct(private readonly Settings $settings)
    {
    }

    /**
     * @return string
     */
    public function toXml(): string
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $entity = $doc->createElementNS(self::NS_METADATA, 'md:EntityDescriptor');
        $entity->setAttribute('entityID', $this->settings->getSpEntityId());
        $doc->appendChild($entity);

        $descriptor = $doc->createElementNS(self::NS_METADATA, 'md:SPSSODescriptor');
        $descriptor->setAttribute('protocolSupportEnumeration', 'urn:oasis:names:tc:SAML:2.0:protocol');
        // We do not sign AuthnRequests yet, but we do require the assertion
        // itself to be signed.
        $descriptor->setAttribute('AuthnRequestsSigned', 'false');
        $descriptor->setAttribute('WantAssertionsSigned', 'true');
        $entity->appendChild($descriptor);

        $nameIdFormat = $doc->createElementNS(self::NS_METADATA, 'md:NameIDFormat', $this->settings->getNameIdFormat());
        $descriptor->appendChild($nameIdFormat);

        $service = $doc->createElementNS(self::NS_METADATA, 'md:AssertionConsumerService');
        $service->setAttribute('Binding', self::BINDING_POST);
        $service->setAttribute('Location', $this->settings->getAcsUrl());
        $service->setAttribute('index', '0');
        $service->setAttribute('isDefault', 'true');
        $descriptor->appendChild($service);

        return $doc->saveXML();
    }
}
