<?php

namespace Appwrite\Event\Delivery;

final readonly class Fanout
{
    public function __construct(
        private Receipt $receipts,
    ) {
    }

    public function getIdentity(
        string $projectId,
        string $projectInternalId,
        string $envelopeId,
        Sink $sink,
        string $targetId,
    ): string {
        return \substr(
            \hash('sha256', $projectId . "\0" . $projectInternalId . "\0" . $envelopeId . "\0" . $sink->value . "\0" . $targetId),
            0,
            36,
        );
    }

    public function deliver(
        string $projectId,
        string $projectInternalId,
        string $envelopeId,
        Sink $sink,
        string $targetId,
        callable $delivery,
    ): bool {
        if ($envelopeId === '') {
            $delivery();

            return true;
        }

        $identity = $this->getIdentity($projectId, $projectInternalId, $envelopeId, $sink, $targetId);
        if ($this->receipts->isComplete($identity)) {
            return false;
        }

        $delivery();
        $this->receipts->complete($identity, $projectId, $projectInternalId, $envelopeId, $sink, $targetId);

        return true;
    }
}
