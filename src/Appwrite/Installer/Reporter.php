<?php

namespace Appwrite\Installer;

use Utopia\Fetch\Adapter;
use Utopia\Fetch\Client;

/**
 * Sends a Report to cloud's installations endpoint. Reporting is best effort:
 * unsendable reports are skipped and failures never reach the installer.
 */
final readonly class Reporter
{
    public const string URL = 'https://cloud.appwrite.io/v1/growth/installations';
    public const string PROJECT = 'console';
    public const int TIMEOUT = 5000;

    /**
     * @param Adapter|null $adapter HTTP adapter, defaulting to the fetch client's own (curl)
     */
    public function __construct(
        private ?Adapter $adapter = null,
    ) {
    }

    public function send(Report $report): void
    {
        if (!$report->sendable()) {
            return;
        }

        try {
            (new Client($this->adapter))
                ->setConnectTimeout(self::TIMEOUT)
                ->setTimeout(self::TIMEOUT)
                ->setUserAgent($report->userAgent())
                ->addHeader('Content-Type', Client::CONTENT_TYPE_APPLICATION_JSON)
                ->addHeader('X-Appwrite-Project', self::PROJECT)
                ->fetch(self::URL, Client::METHOD_POST, $report->payload());
        } catch (\Throwable) {
            // tracking shouldn't block installation
        }
    }
}
