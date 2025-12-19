<?php

use Appwrite\OpenSSL\OpenSSL;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\System\System;

Database::addFilter(
    'casting',
    function (mixed $value) {
        return json_encode(['value' => $value], JSON_PRESERVE_ZERO_FRACTION);
    },
    function (mixed $value) {
        if (is_null($value)) {
            return;
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
        $formatOptions = \json_decode($attribute->getAttribute('formatOptions', '[]'), true);
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
                ->setAttribute('max', $formatOptions['max']);
        }

        return $value;
    }
);

Database::addFilter(
    'subQueryAttributes',
    function (mixed $value) {
        return;
    },
    function (mixed $value, Document $document, Database $database) {
        $attributes = $database->getAuthorization()->skip(fn () => $database->find('attributes', [
            Query::equal('collectionInternalId', [$document->getSequence()]),
            Query::equal('databaseInternalId', [$document->getAttribute('databaseInternalId')]),
            Query::limit($database->getLimitForAttributes()),
        ]));

        foreach ($attributes as $attribute) {
            $attributeType = $attribute->getAttribute('type');

            switch ($attributeType) {
                case Database::VAR_RELATIONSHIP:
                    $options = $attribute->getAttribute('options');
                    foreach ($options as $key => $value) {
                        $attribute->setAttribute($key, $value);
                    }
                    $attribute->removeAttribute('options');
                    break;

                case Database::VAR_STRING:
                    $filters = $attribute->getAttribute('filters', []);
                    $attribute->setAttribute('encrypt', in_array('encrypt', $filters));
                    break;
            }
        }

        return $attributes;
    }
);

Database::addFilter(
    'subQueryIndexes',
    function (mixed $value) {
        return;
    },
    function (mixed $value, Document $document, Database $database) {
        return $database->getAuthorization()->skip(fn () => $database
            ->find('indexes', [
                Query::equal('collectionInternalId', [$document->getSequence()]),
                Query::equal('databaseInternalId', [$document->getAttribute('databaseInternalId')]),
                Query::limit($database->getLimitForIndexes()),
            ]));
    }
);

Database::addFilter(
    'subQueryPlatforms',
    function (mixed $value) {
        return;
    },
    function (mixed $value, Document $document, Database $database) {
        return $database->getAuthorization()->skip(fn () => $database
            ->find('platforms', [
                Query::equal('projectInternalId', [$document->getSequence()]),
                Query::limit(APP_LIMIT_SUBQUERY),
            ]));
    }
);

Database::addFilter(
    'subQueryKeys',
    function (mixed $value) {
        return;
    },
    function (mixed $value, Document $document, Database $database) {
        return $database->getAuthorization()->skip(fn () => $database
            ->find('keys', [
                Query::equal('projectInternalId', [$document->getSequence()]),
                Query::limit(APP_LIMIT_SUBQUERY),
            ]));
    }
);

Database::addFilter(
    'subQueryDevKeys',
    function (mixed $value) {
        return;
    },
    function (mixed $value, Document $document, Database $database) {
        return $database->getAuthorization()->skip(fn () => $database
            ->find('devKeys', [
                Query::equal('projectInternalId', [$document->getSequence()]),
                Query::limit(APP_LIMIT_SUBQUERY),
            ]));
    }
);

Database::addFilter(
    'subQueryWebhooks',
    function (mixed $value) {
        return;
    },
    function (mixed $value, Document $document, Database $database) {
        return $database->getAuthorization()->skip(fn () => $database
            ->find('webhooks', [
                Query::equal('projectInternalId', [$document->getSequence()]),
                Query::limit(APP_LIMIT_SUBQUERY),
            ]));
    }
);

Database::addFilter(
    'subQuerySessions',
    function (mixed $value) {
        return;
    },
    function (mixed $value, Document $document, Database $database) {
        return  $database->getAuthorization()->skip(fn () => $database->find('sessions', [
            Query::equal('userInternalId', [$document->getSequence()]),
            Query::limit(APP_LIMIT_SUBQUERY),
        ]));
    }
);

Database::addFilter(
    'subQueryTokens',
    function (mixed $value) {
        return;
    },
    function (mixed $value, Document $document, Database $database) {
        return  $database->getAuthorization()->skip(fn () => $database
            ->find('tokens', [
                Query::equal('userInternalId', [$document->getSequence()]),
                Query::limit(APP_LIMIT_SUBQUERY),
            ]));
    }
);

Database::addFilter(
    'subQueryChallenges',
    function (mixed $value) {
        return;
    },
    function (mixed $value, Document $document, Database $database) {
        return  $database->getAuthorization()->skip(fn () => $database
            ->find('challenges', [
                Query::equal('userInternalId', [$document->getSequence()]),
                Query::limit(APP_LIMIT_SUBQUERY),
            ]));
    }
);

Database::addFilter(
    'subQueryAuthenticators',
    function (mixed $value) {
        return;
    },
    function (mixed $value, Document $document, Database $database) {
        return $database->getAuthorization()->skip(fn () => $database
            ->find('authenticators', [
                Query::equal('userInternalId', [$document->getSequence()]),
                Query::limit(APP_LIMIT_SUBQUERY),
            ]));
    }
);

Database::addFilter(
    'subQueryMemberships',
    function (mixed $value) {
        return;
    },
    function (mixed $value, Document $document, Database $database) {
        return  $database->getAuthorization()->skip(fn () => $database
            ->find('memberships', [
                Query::equal('userInternalId', [$document->getSequence()]),
                Query::limit(APP_LIMIT_SUBQUERY),
            ]));
    }
);

Database::addFilter(
    'subQueryVariables',
    function (mixed $value) {
        return;
    },
    function (mixed $value, Document $document, Database $database) {
        $resourceType = match ($document->getCollection()) {
            'functions' => ['function'],
            'sites' => ['site'],
            default => ['function', 'site']
        };

        return $database->getAuthorization()->skip(fn () => $database
            ->find('variables', [
                Query::equal('resourceInternalId', [$document->getSequence()]),
                Query::equal('resourceType', $resourceType),
                Query::orderAsc('resourceType'),
                Query::orderAsc(),
                Query::limit(APP_LIMIT_SUBQUERY),
            ]));
    }
);

Database::addFilter(
    'encrypt',
    function (mixed $value) {
        $key = System::getEnv('_APP_OPENSSL_KEY_V1');
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
            return;
        }
        $value = json_decode($value, true);
        $key = System::getEnv('_APP_OPENSSL_KEY_V' . $value['version']);

        return OpenSSL::decrypt($value['data'], $value['method'], $key, 0, hex2bin($value['iv']), hex2bin($value['tag']));
    }
);

Database::addFilter(
    'subQueryProjectVariables',
    function (mixed $value) {
        return;
    },
    function (mixed $value, Document $document, Database $database) {
        return $database->getAuthorization()->skip(fn () => $database
            ->find('variables', [
                Query::equal('resourceType', ['project']),
                Query::limit(APP_LIMIT_SUBQUERY)
            ]));
    }
);

Database::addFilter(
    'userSearch',
    function (mixed $value, Document $user) {
        $searchValues = [
            $user->getId(),
            $user->getAttribute('email', ''),
            $user->getAttribute('name', ''),
            $user->getAttribute('phone', '')
        ];

        foreach ($user->getAttribute('labels', []) as $label) {
            $searchValues[] = 'label:' . $label;
        }

        $search = implode(' ', \array_filter($searchValues));

        return $search;
    },
    function (mixed $value) {
        return $value;
    }
);

Database::addFilter(
    'subQueryTargets',
    function (mixed $value) {
        return;
    },
    function (mixed $value, Document $document, Database $database) {
        return  $database->getAuthorization()->skip(fn () => $database
            ->find('targets', [
                Query::equal('userInternalId', [$document->getSequence()]),
                Query::limit(APP_LIMIT_SUBQUERY)
            ]));
    }
);

Database::addFilter(
    'subQueryTopicTargets',
    function (mixed $value) {
        return;
    },
    function (mixed $value, Document $document, Database $database) {
        $targetIds =  $database->getAuthorization()->skip(fn () => \array_map(
            fn ($document) => $document->getAttribute('targetInternalId'),
            $database->find('subscribers', [
                Query::equal('topicInternalId', [$document->getSequence()]),
                Query::limit(APP_LIMIT_SUBSCRIBERS_SUBQUERY)
            ])
        ));
        if (\count($targetIds) > 0) {
            return $database->skipValidation(fn () => $database->find('targets', [
                Query::equal('$sequence', $targetIds)
            ]));
        }
        return [];
    }
);

Database::addFilter(
    'providerSearch',
    function (mixed $value, Document $provider) {
        $searchValues = [
            $provider->getId(),
            $provider->getAttribute('name', ''),
            $provider->getAttribute('provider', ''),
            $provider->getAttribute('type', '')
        ];

        $search = \implode(' ', \array_filter($searchValues));

        return $search;
    },
    function (mixed $value) {
        return $value;
    }
);

Database::addFilter(
    'topicSearch',
    function (mixed $value, Document $topic) {
        $searchValues = [
            $topic->getId(),
            $topic->getAttribute('name', ''),
            $topic->getAttribute('description', ''),
        ];

        $search = \implode(' ', \array_filter($searchValues));

        return $search;
    },
    function (mixed $value) {
        return $value;
    }
);

Database::addFilter(
    'messageSearch',
    function (mixed $value, Document $message) {
        $searchValues = [
            $message->getId(),
            $message->getAttribute('description', ''),
            $message->getAttribute('status', ''),
        ];

        $data = \json_decode($message->getAttribute('data', []), true);
        $providerType = $message->getAttribute('providerType', '');

        switch ($providerType) {
            case MESSAGE_TYPE_EMAIL:
                $searchValues[] = $data['subject'];
                $searchValues[] = MESSAGE_TYPE_EMAIL;
                break;
            case MESSAGE_TYPE_SMS:
                $searchValues[] = $data['content'];
                $searchValues[] = MESSAGE_TYPE_SMS;
                break;
            case MESSAGE_TYPE_PUSH:
                $searchValues[] = $data['title'] ?? '';
                $searchValues[] = MESSAGE_TYPE_PUSH;
                break;
        }

        $search = \implode(' ', \array_filter($searchValues));

        return $search;
    },
    function (mixed $value) {
        return $value;
    }
);
