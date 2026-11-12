<?php

// ruleid: php.appwrite.xml-external-entities
$xml = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NOENT);

// ruleid: php.appwrite.xml-external-entities
$dom->loadXML($body, LIBXML_DTDLOAD | LIBXML_NONET);

// ruleid: php.appwrite.xml-external-entities
libxml_disable_entity_loader(false);

// ok: php.appwrite.xml-external-entities
$xml = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NONET);

// ok: php.appwrite.xml-external-entities
$dom->loadXML($body);
