<?php

namespace Appwrite\Certificates;

use Appwrite\Event\Message\Certificate as CertificateMessage;
use Appwrite\Event\Publisher\Certificate as CertificatePublisher;
use Utopia\Database\Document;
use Utopia\Domains\Domain;

/**
 * Schedules TLS certificate generation jobs for proxy domains.
 */
class Scheduler
{
    /**
     * Enqueue certificate generation for a domain.
     *
     * When $requirePublicHostname is true, jobs are only scheduled for hostnames
     * that can receive a publicly trusted certificate (known public suffix and
     * not a reserved test/localhost TLD). Local development domains such as
     * *.functions.localhost are intentionally skipped.
     *
     * @return bool Whether a certificate job was enqueued
     */
    public static function enqueueGeneration(
        CertificatePublisher $publisher,
        Document $project,
        string $domain,
        string $domainType = '',
        bool $skipRenewCheck = false,
        bool $requirePublicHostname = false,
    ): bool {
        if (empty($domain)) {
            return false;
        }

        if ($requirePublicHostname) {
            try {
                $hostname = new Domain($domain);
            } catch (\Throwable) {
                return false;
            }

            if (empty($hostname->get()) || !$hostname->isKnown() || $hostname->isTest()) {
                return false;
            }
        }

        $publisher->enqueue(new CertificateMessage(
            project: $project,
            domain: new Document([
                'domain' => $domain,
                'domainType' => $domainType,
            ]),
            skipRenewCheck: $skipRenewCheck,
            action: \Appwrite\Event\Certificate::ACTION_GENERATION,
        ));

        return true;
    }
}
