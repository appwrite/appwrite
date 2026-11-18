<?php

namespace Appwrite\Vcs;

use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Validator\Authorization;

/**
 * Makes a provider's at-least-once webhook deliveries deploy once. Each piece
 * of a delivery's work, such as one repository's push of one branch, is
 * claimed by the attempt that does it and marked done when that attempt
 * finishes. A retry skips work that is done, and is refused while another
 * attempt still holds the work: that attempt can still fail, and a retry
 * acknowledged in the meantime would leave its work undone for good.
 *
 * An attempt that stops without finishing or giving its claim back, such as
 * one whose worker was restarted, leaves the claim behind. Once the claim is
 * {@see self::ABANDONED_AFTER} old, the next attempt takes the work over
 * under a claim of its own, keyed by the one it replaces, so only one of
 * several retries racing for it gets it.
 *
 * Claims and done markers are documents in the platform database's
 * vcsCommentLocks collection, which every region shares.
 */
readonly class DeliveryClaims
{
    /** Seconds after which a claim that was neither finished nor given back is taken over */
    public const int ABANDONED_AFTER = 900;

    /** Claims one piece of work can go through before a retry stops looking for a free one */
    private const int GENERATIONS = 10;

    private const string COLLECTION = 'vcsCommentLocks';

    public function __construct(
        private Database $dbForPlatform,
        private Authorization $authorization,
        private string $provider,
    ) {
    }

    /**
     * Claims a piece of work for this attempt.
     *
     * @param string $work Names the work within the provider, such as "<delivery>:<branch>:<repository>"
     */
    public function take(string $work): DeliveryClaim
    {
        if (!$this->find($this->id('done', $work))->isEmpty()) {
            return new DeliveryClaim(DeliveryClaim::DONE, $work);
        }

        $id = $this->id('claim', $work);
        $resumedFrom = null;

        for ($generation = 0; $generation < self::GENERATIONS; $generation++) {
            if ($this->create($id)) {
                return new DeliveryClaim(DeliveryClaim::TAKEN, $work, $id, $resumedFrom);
            }

            $claim = $this->find($id);

            // Given back in between, or still held: either way another attempt
            // has it, and the delivery has to come back.
            if ($claim->isEmpty() || !$this->isAbandoned($claim)) {
                return new DeliveryClaim(DeliveryClaim::BUSY, $work);
            }

            // The work goes back to when the first attempt on it began
            $resumedFrom ??= DateTime::format(new \DateTime($claim->getCreatedAt()));
            $id = $this->id('claim', $work . ':' . $claim->getCreatedAt());
        }

        return new DeliveryClaim(DeliveryClaim::BUSY, $work);
    }

    /**
     * Marks the work done, so no retry does it again. The claim stays: a retry
     * that read no done marker just before this would otherwise take it.
     */
    public function finish(DeliveryClaim $claim): void
    {
        $this->create($this->id('done', $claim->work));
    }

    /**
     * Gives the work back, so the next retry does it.
     */
    public function release(DeliveryClaim $claim): void
    {
        $this->authorization->skip(fn () => $this->dbForPlatform->deleteDocument(self::COLLECTION, $claim->id));
    }

    /**
     * Document ids are capped at 36 characters; a digest keeps every id within it.
     */
    private function id(string $kind, string $work): string
    {
        return $this->provider . '-' . \substr(\hash('sha256', $kind . ':' . $work), 0, 35 - \strlen($this->provider));
    }

    private function create(string $id): bool
    {
        try {
            $this->authorization->skip(fn () => $this->dbForPlatform->createDocument(self::COLLECTION, new Document(['$id' => $id])));
        } catch (Duplicate) {
            return false;
        }

        return true;
    }

    private function find(string $id): Document
    {
        return $this->authorization->skip(fn () => $this->dbForPlatform->getDocument(self::COLLECTION, $id));
    }

    private function isAbandoned(Document $claim): bool
    {
        return new \DateTime($claim->getCreatedAt()) < new \DateTime('-' . self::ABANDONED_AFTER . ' seconds');
    }
}
