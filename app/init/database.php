<?php

use Utopia\App;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\DatetimeValidator;
use Utopia\Database\Validator\Structure;
use Appwrite\Network\Validator\Email;
use Appwrite\OpenSSL\OpenSSL;
use Utopia\Validator\Range;
use Utopia\Validator\IP;
use Utopia\Validator\URL;
use Utopia\Validator\WhiteList;

/**
 * New DB Filters
 */
Database::addFilter(
    'casting',
    function (mixed $value) {
        return json_encode(['value' => $value], JSON_PRESERVE_ZERO_FRACTION);
    },
    function (mixed $value) {
        if (is_null($value)) {
            return null;
        }

        return json_decode($value, true)['value'];
    }
);

Database::addFilter(
    'enum',
    function (mixed $value, Document $attribute) {
        if ($attribute->isSet('elements')) {
            $attribute->removeAttribute('elements');
        }

        return $value;
    },
    function (mixed $value, Document $attribute) {
        $formatOptions = json_decode($attribute->getAttribute('formatOptions', '[]'), true);
        if (isset($formatOptions['elements'])) {
            $attribute->setAttribute('elements', $formatOptions['elements']);
        }

        return $value;
    }
);

Database::addFilter(
    'range',
    function (mixed $value, Document $attribute) {
        if ($attribute->isSet('min')) {
            $attribute->removeAttribute('min');
        }
        if ($attribute->isSet('max')) {
            $attribute->removeAttribute('max');
        }

        return $value;
    },
    function (mixed $value, Document $attribute) {
        $formatOptions = json_decode($attribute->getAttribute('formatOptions', '[]'), true);
        if (isset($formatOptions['min']) || isset($formatOptions['max'])) {
            $attribute
                ->setAttribute('min', $formatOptions['min'])
                ->setAttribute('max', $formatOptions['max'])
            ;
        }

        return $value;
    }
);

Database::addFilter(
    'subQueryAttributes',
    function (mixed $value) {
        return null;
    },
    function (mixed $value, Document $document, Database $database) {
        return $database
            ->find('attributes', [
                Query::equal('collectionInternalId', [$document->getInternalId()]),
                Query::equal('databaseInternalId', [$document->getAttribute('databaseInternalId')]),
                Query::limit($database->getLimitForAttributes()),
            ]);
    }
);

Database::addFilter(
    'subQueryIndexes',
    function (mixed $value) {
        return null;
    },
    function (mixed $value, Document $document, Database $database) {
        return $database
            ->find('indexes', [
                Query::equal('collectionInternalId', [$document->getInternalId()]),
                Query::equal('databaseInternalId', [$document->getAttribute('databaseInternalId')]),
                Query::limit(64),
            ]);
    }
);

Database::addFilter(
    'subQueryPlatforms',
    function (mixed $value) {
        return null;
    },
    function (mixed $value, Document $document, Database $database) {
        return $database
            ->find('platforms', [
                Query::equal('projectInternalId', [$document->getInternalId()]),
                Query::limit(APP_LIMIT_SUBQUERY),
            ]);
    }
);

Database::addFilter(
    'subQueryDomains',
    function (mixed $value) {
        return null;
    },
    function (mixed $value, Document $document, Database $database) {
        return $database
            ->find('domains', [
                Query::equal('projectInternalId', [$document->getInternalId()]),
                Query::limit(APP_LIMIT_SUBQUERY),
            ]);
    }
);

Database::addFilter(
    'subQueryKeys',
    function (mixed $value) {
        return null;
    },
    function (mixed $value, Document $document, Database $database) {
        return $database
            ->find('keys', [
                Query::equal('projectInternalId', [$document->getInternalId()]),
                Query::limit(APP_LIMIT_SUBQUERY),
            ]);
    }
);

Database::addFilter(
    'subQueryWebhooks',
    function (mixed $value) {
        return null;
    },
    function (mixed $value, Document $document, Database $database) {
        return $database
            ->find('webhooks', [
                Query::equal('projectInternalId', [$document->getInternalId()]),
                Query::limit(APP_LIMIT_SUBQUERY),
            ]);
    }
);

Database::addFilter(
    'subQuerySessions',
    function (mixed $value) {
        return null;
    },
    function (mixed $value, Document $document, Database $database) {
        return Authorization::skip(fn () => $database->find('sessions', [
            Query::equal('userInternalId', [$document->getInternalId()]),
            Query::limit(APP_LIMIT_SUBQUERY),
        ]));
    }
);

Database::addFilter(
    'subQueryTokens',
    function (mixed $value) {
        return null;
    },
    function (mixed $value, Document $document, Database $database) {
        return Authorization::skip(fn() => $database
            ->find('tokens', [
                Query::equal('userInternalId', [$document->getInternalId()]),
                Query::limit(APP_LIMIT_SUBQUERY),
            ]));
    }
);

Database::addFilter(
    'subQueryMemberships',
    function (mixed $value) {
        return null;
    },
    function (mixed $value, Document $document, Database $database) {
        return Authorization::skip(fn() => $database
            ->find('memberships', [
                Query::equal('userInternalId', [$document->getInternalId()]),
                Query::limit(APP_LIMIT_SUBQUERY),
            ]));
    }
);

Database::addFilter(
    'subQueryVariables',
    function (mixed $value) {
        return null;
    },
    function (mixed $value, Document $document, Database $database) {
        return $database
            ->find('variables', [
                Query::equal('functionInternalId', [$document->getInternalId()]),
                Query::limit(APP_LIMIT_SUBQUERY),
            ]);
    }
);

Database::addFilter(
    'encrypt',
    function (mixed $value) {
        $key = App::getEnv('_APP_OPENSSL_KEY_V1');
        $iv = OpenSSL::randomPseudoBytes(OpenSSL::cipherIVLength(OpenSSL::CIPHER_AES_128_GCM));
        $tag = null;

        return json_encode([
            'data' => OpenSSL::encrypt($value, OpenSSL::CIPHER_AES_128_GCM, $key, 0, $iv, $tag),
            'method' => OpenSSL::CIPHER_AES_128_GCM,
            'iv' => \bin2hex($iv),
            'tag' => \bin2hex($tag ?? ''),
            'version' => '1',
        ]);
    },
    function (mixed $value) {
        if (is_null($value)) {
            return null;
        }
        $value = json_decode($value, true);
        $key = App::getEnv('_APP_OPENSSL_KEY_V' . $value['version']);

        return OpenSSL::decrypt($value['data'], $value['method'], $key, 0, hex2bin($value['iv']), hex2bin($value['tag']));
    }
);

/**
 * DB Formats
 */
Structure::addFormat(APP_DATABASE_ATTRIBUTE_EMAIL, function () {
    return new Email();
}, Database::VAR_STRING);

Structure::addFormat(APP_DATABASE_ATTRIBUTE_DATETIME, function () {
    return new DatetimeValidator();
}, Database::VAR_DATETIME);

Structure::addFormat(APP_DATABASE_ATTRIBUTE_ENUM, function ($attribute) {
    $elements = $attribute['formatOptions']['elements'];
    return new WhiteList($elements, true);
}, Database::VAR_STRING);

Structure::addFormat(APP_DATABASE_ATTRIBUTE_IP, function () {
    return new IP();
}, Database::VAR_STRING);

Structure::addFormat(APP_DATABASE_ATTRIBUTE_URL, function () {
    return new URL();
}, Database::VAR_STRING);

Structure::addFormat(APP_DATABASE_ATTRIBUTE_INT_RANGE, function ($attribute) {
    $min = $attribute['formatOptions']['min'] ?? -INF;
    $max = $attribute['formatOptions']['max'] ?? INF;
    return new Range($min, $max, Range::TYPE_INTEGER);
}, Database::VAR_INTEGER);

Structure::addFormat(APP_DATABASE_ATTRIBUTE_FLOAT_RANGE, function ($attribute) {
    $min = $attribute['formatOptions']['min'] ?? -INF;
    $max = $attribute['formatOptions']['max'] ?? INF;
    return new Range($min, $max, Range::TYPE_FLOAT);
}, Database::VAR_FLOAT);
