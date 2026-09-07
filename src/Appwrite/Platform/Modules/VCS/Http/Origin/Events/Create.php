<?php

namespace Appwrite\Platform\Modules\VCS\Http\Origin\Events;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\VCS\Http\Events\Base;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Appwrite\Vcs\Factory as VcsFactory;
use Appwrite\Vcs\InstallationTokens;
use Utopia\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Validator\Authorization;
use Utopia\Span\Span;
use Utopia\VCS\Adapter\Git;
use Utopia\VCS\Adapter\Git\Origin;

class Create extends Base
{
    /**
     * Origin's active signing keys, cached for the worker's lifetime. The set
     * rotates rarely and a delivery that fails on a stale set refetches once.
     *
     * @var array<string>|null
     */
    protected static ?array $signingKeys = null;

    public static function getName()
    {
        return 'createVCSOriginEvent';
    }

    public static function getProvider(): string
    {
        return 'origin';
    }

    public static function getProviderName(): string
    {
        return 'Origin';
    }

    protected function getCommitEmails(): array
    {
        return [APP_VCS_GITHUB_EMAIL, APP_VCS_ORIGIN_EMAIL];
    }

    protected function getPushEvents(): array
    {
        return [Origin::EVENT_PUSH];
    }

    protected function getPullRequestEvents(): array
    {
        return [Origin::EVENT_PULL_REQUEST];
    }

    protected function getInstallationEvents(): array
    {
        return [Origin::EVENT_INSTALLATION];
    }

    /**
     * Origin names an event by its action, as `pull_request.opened`.
     */
    protected function matchesEvent(string $event, array $names): bool
    {
        foreach ($names as $name) {
            if ($event === $name || \str_starts_with($event, $name . '.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Origin signs the SHA-256 of "<webhook-id>.<webhook-timestamp>.<raw body>"
     * with its own Ed25519 key, verified against its published JWKS rather
     * than a shared secret. Stale timestamps are replays.
     */
    protected function verifyDelivery(Request $request, Git $vcs, string $payload, callable $vcsWebhookSecret): void
    {
        $signature = $request->getHeaderLine($vcs->getSignatureHeaderName(), '');
        $deliveryId = $request->getHeaderLine('webhook-id', '');
        $timestamp = $request->getHeaderLine('webhook-timestamp', '');

        $valid = false;
        if ($vcs instanceof Origin && !empty($deliveryId) && \ctype_digit($timestamp) && \abs(\time() - (int) $timestamp) <= 300) {
            $signedContent = $deliveryId . '.' . $timestamp . '.' . $payload;

            foreach ($this->signingKeys($vcs) as $publicKey) {
                if ($vcs->validateWebhookEvent($signedContent, $signature, $publicKey)) {
                    $valid = true;
                    break;
                }
            }

            // The key set may have rotated since it was cached.
            if (!$valid) {
                foreach ($this->signingKeys($vcs, refresh: true) as $publicKey) {
                    if ($vcs->validateWebhookEvent($signedContent, $signature, $publicKey)) {
                        $valid = true;
                        break;
                    }
                }
            }
        }

        Span::add('vcs.origin.event.signature.valid', $valid);

        if (!$valid) {
            throw new Exception(Exception::GENERAL_ACCESS_FORBIDDEN, 'Invalid webhook payload signature. The delivery could not be verified against Origin\'s published signing keys.');
        }
    }

    /**
     * Origin delivers at least once and retries anything not acknowledged
     * with a 2xx in time. Processing here is synchronous and can outlast
     * that window, so claim the delivery id before doing the work - a
     * retry of a delivery already being processed must not deploy again.
     * TODO: acknowledge first and process asynchronously instead.
     */
    protected function claimDelivery(Request $request, Response $response, Database $dbForPlatform, Authorization $authorization): bool
    {
        $deliveryId = $request->getHeaderLine('webhook-id', '');

        try {
            $authorization->skip(fn () => $dbForPlatform->createDocument('vcsCommentLocks', new Document([
                '$id' => 'origin-delivery-' . $deliveryId,
            ])));
        } catch (Duplicate) {
            $response->json(['events' => [], 'duplicate' => true]);

            return false;
        }

        return true;
    }

    /**
     * Origin returns Cursor's rows verbatim, which name the previous path in
     * camelCase.
     */
    protected function getAffectedFiles(array $prFiles): array
    {
        return [
            ...array_column($prFiles, 'filename'),
            ...array_filter(array_column($prFiles, 'previousFilename')),
        ];
    }

    protected function resolveAdapters(
        array $repositories,
        array $parsedPayload,
        VcsFactory $vcsFactory,
        InstallationTokens $installationTokens,
        Database $dbForPlatform,
        Authorization $authorization,
        array &$errors,
    ): array {
        return $this->resolveAdaptersFromDelivery($repositories, $parsedPayload, $vcsFactory);
    }

    /**
     * Origin's active signing keys, from the adapter, cached for the worker's
     * lifetime - the adapter memoizes only per instance, and a new adapter is
     * built for every delivery.
     *
     * @return array<string>
     */
    protected function signingKeys(Origin $vcs, bool $refresh = false): array
    {
        if (!$refresh && self::$signingKeys !== null) {
            return self::$signingKeys;
        }

        $keys = [];

        try {
            $keys = $vcs->getSigningKeys($refresh);
        } catch (\Throwable $e) {
            Console::warning('Failed to fetch Origin signing keys: ' . $e->getMessage());
        }

        // Never cache an empty set - a fetch hiccup would reject deliveries
        // until the worker restarts.
        if (!empty($keys)) {
            self::$signingKeys = $keys;
        }

        return self::$signingKeys ?? [];
    }
}
