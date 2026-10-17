<?php

namespace Tests\Compat\Domains;

use Utopia\Domains\Registrar;
use Utopia\Domains\Registrar\Adapter;
use Utopia\Domains\Registrar\Contact;
use Utopia\Domains\Registrar\Domain;
use Utopia\Domains\Registrar\Price;
use Utopia\Domains\Registrar\Renewal;
use Utopia\Domains\Registrar\TransferStatus;
use Utopia\Domains\Registrar\UpdateDetails;

/**
 * A registrar adapter that implements only what it must, to reach the
 * defaults `Registrar\Adapter` provides (`updateNameservers()`). Its twin is
 * `Bare` in crates/tools/compat/src/libs/domains.rs.
 */
final class Bare extends Adapter
{
    public function getName(): string
    {
        return 'bare';
    }

    public function available(array $domains): array
    {
        throw new \LogicException('not used');
    }

    public function purchase(string $domain, array|Contact $contacts, int $periodYears = 1, array $nameservers = [], bool $autorenewEnabled = false, ?float $purchasePrice = null): string
    {
        throw new \LogicException('not used');
    }

    public function suggest(array|string $query, array $tlds = [], ?int $limit = null, ?string $filterType = null, ?int $priceMax = null, ?int $priceMin = null): array
    {
        throw new \LogicException('not used');
    }

    public function tlds(): array
    {
        throw new \LogicException('not used');
    }

    public function getDomain(string $domain): Domain
    {
        throw new \LogicException('not used');
    }

    public function updateDomain(string $domain, UpdateDetails $details): bool
    {
        throw new \LogicException('not used');
    }

    public function getPrice(string $domain, int $periodYears = 1, string $regType = Registrar::REG_TYPE_NEW, int $ttl = 3600): Price
    {
        throw new \LogicException('not used');
    }

    public function renew(string $domain, int $periodYears): Renewal
    {
        throw new \LogicException('not used');
    }

    public function transfer(string $domain, string $authCode, ?float $purchasePrice = null): string
    {
        throw new \LogicException('not used');
    }

    public function getAuthCode(string $domain): string
    {
        throw new \LogicException('not used');
    }

    public function checkTransferStatus(string $domain): TransferStatus
    {
        throw new \LogicException('not used');
    }

    public function cancelPurchase(): bool
    {
        throw new \LogicException('not used');
    }
}
