<?php

namespace Tests\Compat\Domains;

use Tests\Compat\Adapter as Base;
use Tests\Compat\Fault;
use Tests\Compat\Session;
use Utopia\Cache\Adapter\Memory;
use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache as UtopiaCache;
use Utopia\Domains\Adapter as Api;
use Utopia\Domains\Cache;
use Utopia\Domains\Domain;
use Utopia\Domains\Registrar;
use Utopia\Domains\Registrar\Adapter as RegistrarAdapter;
use Utopia\Domains\Registrar\Adapter\Mock;
use Utopia\Domains\Registrar\Adapter\NameCom;
use Utopia\Domains\Registrar\Adapter\OpenSRS;
use Utopia\Domains\Registrar\Contact;
use Utopia\Domains\Registrar\Domain as RegisteredDomain;
use Utopia\Domains\Registrar\Price;
use Utopia\Domains\Registrar\Renewal;
use Utopia\Domains\Registrar\TransferStatus;
use Utopia\Domains\Registrar\UpdateDetails;
use Utopia\Domains\Validator\ApexDomain;
use Utopia\Domains\Validator\PublicDomain;
use Utopia\Domains\Validator\RegistrableDomain;
use Utopia\Validator\Validator;

require_once __DIR__ . '/Transport.php';
require_once __DIR__ . '/Bare.php';

/**
 * Maps tests/compat/domains/spec.json operations onto utopia-php/domains. Glue only: no logic.
 *
 * Registrar adapters that call an API get a recorded Transport: cases give
 * the responses, and every call reports the requests the adapter sent.
 */
final class Adapter implements Base
{
    public function operations(): array
    {
        return [
            'domain.parse' => fn (array $a, Session $s) => self::parts(new Domain($a['domain'])),

            'validator.is_valid' => fn (array $a, Session $s) => self::validator($a)->isValid($a['value'] ?? null),
            'validator.describe' => fn (array $a, Session $s) => [
                'description' => self::validator($a)->getDescription(),
                'type' => self::validator($a)->getType(),
                'array' => self::validator($a)->isArray(),
            ],
            'validator.allow' => function (array $a, Session $s) {
                PublicDomain::allow($a['domains']);

                return;
            },

            'cache.new' => fn (array $a, Session $s) => $s->handle(new Cache(new UtopiaCache(
                ($a['store'] ?? 'memory') === 'none' ? new None() : new Memory()
            ))),
            'cache.load' => fn (array $a, Session $s) => self::cache($a, $s)->load($a['domain'], $a['ttl']),
            'cache.save' => fn (array $a, Session $s) => self::cache($a, $s)->save($a['domain'], $a['data']),
            'cache.purge' => fn (array $a, Session $s) => self::cache($a, $s)->purge($a['domain']),

            'contact.to_array' => fn (array $a, Session $s) => self::contact($a['contact'])->toArray(),

            'registrar.new' => fn (array $a, Session $s) => self::registrar($a, $s),
            'registrar.call' => fn (array $a, Session $s) => self::call($a, $s),
            'registrar.once' => fn (array $a, Session $s) => self::call(['registrar' => self::registrar($a, $s)] + $a, $s),
            'registrar.bare_update_nameservers' => fn (array $a, Session $s) => (new Bare('', '', ''))->updateNameservers($a['domain'], $a['nameservers']),

            'api.call' => fn (array $a, Session $s) => (new class ($s->service('domains-http'), $a['key'], $a['secret']) extends Api {
            })->call($a['method'], $a['path'] ?? '', $a['params'] ?? [], $a['headers'] ?? []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function parts(Domain $domain): array
    {
        return [
            'get' => $domain->get(),
            'tld' => $domain->getTLD(),
            'suffix' => $domain->getSuffix(),
            'rule' => $domain->getRule(),
            'name' => $domain->getName(),
            'sub' => $domain->getSub(),
            'apex' => $domain->getApex(),
            'registerable' => $domain->getRegisterable(),
            'known' => $domain->isKnown(),
            'icann' => $domain->isICANN(),
            'private' => $domain->isPrivate(),
            'test' => $domain->isTest(),
        ];
    }

    private static function validator(array $a): Validator
    {
        return match ($a['validator']) {
            'public' => new PublicDomain(),
            'apex' => new ApexDomain(),
            'registrable' => new RegistrableDomain(),
            default => throw new Fault('unknown validator ' . json_encode($a['validator'])),
        };
    }

    private static function cache(array $a, Session $s): Cache
    {
        $cache = $s->get($a['cache']);

        return $cache instanceof Cache ? $cache : throw new Fault('not a cache');
    }

    /**
     * @param array<string, string|null> $c
     */
    private static function contact(array $c): Contact
    {
        return new Contact(
            $c['firstname'] ?? '',
            $c['lastname'] ?? '',
            $c['phone'] ?? '',
            $c['email'] ?? '',
            $c['address1'] ?? '',
            $c['address2'] ?? '',
            $c['address3'] ?? '',
            $c['city'] ?? '',
            $c['state'] ?? '',
            $c['country'] ?? '',
            $c['postalcode'] ?? '',
            $c['org'] ?? '',
            $c['owner'] ?? null,
        );
    }

    /**
     * `contact` (one Contact) or `contacts` (a list, or contacts by key).
     *
     * @return Contact|array<int|string, Contact>
     */
    private static function contacts(array $a): Contact|array
    {
        if (isset($a['contact'])) {
            return self::contact($a['contact']);
        }

        return array_map(self::contact(...), $a['contacts'] ?? []);
    }

    private static function registrar(array $a, Session $s): array
    {
        $spec = $a['adapter'];
        $transport = new Transport();
        $adapter = match ($spec['type']) {
            'mock' => new Mock($spec['taken'] ?? [], $spec['tlds'] ?? [], $spec['price'] ?? 12.99),
            'namecom' => new NameCom($spec['username'], $spec['token'], $spec['endpoint'] ?? 'https://api.name.com', $transport),
            'opensrs' => new OpenSRS($spec['key'], $spec['username'], $spec['password'], $spec['endpoint'] ?? 'https://horizon.opensrs.net:55443', $transport),
            default => throw new Fault('unknown adapter ' . json_encode($spec['type'])),
        };
        $cache = isset($a['cache']) ? self::cache(['cache' => $a['cache']], $s) : null;
        $registrar = new Registrar($adapter, $a['nameservers'] ?? [], $cache, $a['connectTimeout'] ?? 5, $a['timeout'] ?? 10);

        return $s->handle(['registrar' => $registrar, 'adapter' => $adapter, 'transport' => $transport]);
    }

    /**
     * One registrar method: its result or error, and the requests it sent.
     *
     * @return array<string, mixed>
     */
    private static function call(array $a, Session $s): array
    {
        $handle = $s->get($a['registrar']);
        if (!\is_array($handle) || !$handle['registrar'] instanceof Registrar || !$handle['adapter'] instanceof RegistrarAdapter || !$handle['transport'] instanceof Transport) {
            throw new Fault('not a registrar');
        }
        $registrar = $handle['registrar'];
        $adapter = $handle['adapter'];
        $transport = $handle['transport'];
        $transport->queue($a['responses'] ?? []);
        $args = $a['args'] ?? [];
        if ($args instanceof \stdClass) {
            $args = [];
        }

        try {
            $out = ['result' => self::invoke($registrar, $adapter, $a['method'], $args)];
        } catch (Fault $fault) {
            throw $fault;
        } catch (\Throwable $error) {
            $out = ['error' => ['class' => $error::class, 'message' => $error->getMessage(), 'code' => $error->getCode()]];
        }
        if (!$adapter instanceof Mock) {
            $out['requests'] = $transport->take();
        }

        return $out;
    }

    private static function invoke(Registrar $r, RegistrarAdapter $adapter, string $method, array $a): mixed
    {
        $mock = static fn (): Mock => $adapter instanceof Mock ? $adapter : throw new Fault('not a mock');

        return match ($method) {
            'getName' => $r->getName(),
            'available' => $r->available($a['domains']),
            'purchase' => $r->purchase($a['domain'], self::contacts($a), $a['periodYears'] ?? 1, $a['nameservers'] ?? [], $a['autorenewEnabled'] ?? false, $a['purchasePrice'] ?? null),
            'suggest' => $r->suggest($a['query'], $a['tlds'] ?? [], $a['limit'] ?? null, $a['filterType'] ?? null, $a['priceMax'] ?? null, $a['priceMin'] ?? null),
            'tlds' => $r->tlds(),
            'getDomain' => self::domain($r->getDomain($a['domain'])),
            'updateDomain' => $r->updateDomain($a['domain'], new UpdateDetails($a['autoRenew'] ?? null)),
            'updateNameservers' => $r->updateNameservers($a['domain'], $a['nameservers']),
            'getPrice' => self::price($r->getPrice($a['domain'], $a['periodYears'] ?? 1, $a['regType'] ?? Registrar::REG_TYPE_NEW, $a['ttl'] ?? 3600)),
            'renew' => self::renewal($r->renew($a['domain'], $a['periodYears'])),
            'transfer' => $r->transfer($a['domain'], $a['authCode'], $a['purchasePrice'] ?? null),
            'getAuthCode' => $r->getAuthCode($a['domain']),
            'checkTransferStatus' => self::status($r->checkTransferStatus($a['domain'])),
            'cancelPurchase' => $r->cancelPurchase(),
            'getPurchasedDomains' => $mock()->getPurchasedDomains(),
            'getTransferredDomains' => $mock()->getTransferredDomains(),
            'reset' => $mock()->reset(),
            'addTakenDomain' => $mock()->addTakenDomain($a['domain']),
            'addPremiumDomain' => $mock()->addPremiumDomain($a['domain'], $a['price']),
            default => throw new Fault('unknown registrar method ' . $method),
        };
    }

    private static function date(?\DateTime $date): ?string
    {
        return $date?->format('Y-m-d\TH:i:s.uP');
    }

    /**
     * @return array<string, mixed>
     */
    private static function domain(RegisteredDomain $d): array
    {
        return [
            'domain' => $d->domain,
            'createdAt' => self::date($d->createdAt),
            'expiresAt' => self::date($d->expiresAt),
            'autoRenew' => $d->autoRenew,
            'nameservers' => $d->nameservers,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function price(Price $p): array
    {
        return ['price' => $p->price, 'premium' => $p->premium];
    }

    /**
     * @return array<string, mixed>
     */
    private static function renewal(Renewal $r): array
    {
        return ['orderId' => $r->orderId, 'expiresAt' => self::date($r->expiresAt)];
    }

    /**
     * @return array<string, mixed>
     */
    private static function status(TransferStatus $t): array
    {
        return ['status' => $t->status->value, 'reason' => $t->reason, 'timestamp' => self::date($t->timestamp)];
    }
}
