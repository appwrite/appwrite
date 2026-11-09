<?php

namespace Appwrite\Video;

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Utopia\Console;
use Utopia\Storage\Device;
use Utopia\Storage\Device\Local;
use Utopia\Video\Written;

/**
 * Uploads a rendition's segments to the videos device while ffmpeg writes
 * them, from a coroutine of its own, and removes each from the workspace
 * once it is up.
 *
 * The hand-over never waits: the backlog holds references, not files, so the
 * store never holds ffmpeg back and its time never counts against the ffmpeg
 * timeout. The workspace holds exactly the segments the store has not taken.
 *
 * A failed write is kept and ends the uploads; the next hand-over throws it,
 * which stops ffmpeg. Retries belong to the device's client.
 */
final class Uploader
{
    /** Finished segments the store has not taken yet. */
    private readonly Channel $backlog;

    private readonly Local $local;

    /** Cleared by whichever side gives up: a failed write, or abandon(). */
    private bool $shipping = true;

    private ?\Throwable $failure = null;

    private int $bytes = 0;

    /** Segments successfully written to the device. */
    private int $chunks = 0;

    /** Wall time spent inside device write() calls. */
    private float $uploadSeconds = 0.0;

    private ?int $coroutine = null;

    /**
     * @param string $path Directory on the device, with a trailing slash
     */
    public function __construct(
        private readonly Device $device,
        private readonly string $path,
    ) {
        $this->backlog = new Channel(PHP_INT_MAX);
        $this->local = new Local('/');
    }

    /**
     * Starts the coroutine that drains the backlog.
     */
    public function start(): void
    {
        $coroutine = \go(function (): void {
            // Runs until the backlog is closed and empty: finish() and
            // abandon() wait for it, so a failed write must not end it early.
            while (($written = $this->backlog->pop()) instanceof Written) {
                if (!$this->shipping) {
                    continue;
                }
                try {
                    $started = \microtime(true);
                    $this->device->write(
                        $this->path . $written->file,
                        $this->local->read($written->path),
                        \mime_content_type($written->path) ?: 'application/octet-stream'
                    );
                    $this->uploadSeconds += \microtime(true) - $started;
                    $this->bytes += $written->size;
                    $this->chunks++;
                    if (!@\unlink($written->path)) {
                        Console::warning('Videos uploader: failed to remove shipped segment ' . $written->path);
                    }
                } catch (\Throwable $th) {
                    // Span::add() does nothing in this coroutine; take() and
                    // finish() hand the failure back to the run.
                    $this->failure = $th;
                    $this->shipping = false;
                }
            }
        });

        if ($coroutine === false) {
            throw new \Exception('Failed to start the segment uploader');
        }

        $this->coroutine = $coroutine;
    }

    /**
     * Hands over a finished segment. Never waits.
     *
     * @throws \Throwable The write that failed earlier, so the run stops the encode
     */
    public function take(Written $written): void
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        $this->backlog->push($written);
    }

    /**
     * Nothing more will be handed over; the coroutine finishes what is queued.
     */
    public function close(): void
    {
        $this->backlog->close();
    }

    /**
     * Segments handed over that the store has not taken yet.
     */
    public function pending(): int
    {
        return $this->backlog->length();
    }

    /**
     * Waits until every segment handed over is up.
     *
     * @return int Bytes uploaded
     *
     * @throws \Throwable The write that failed
     */
    public function finish(): int
    {
        $this->close();
        $this->join();

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->bytes;
    }

    /**
     * Segments successfully written to the device.
     */
    public function chunks(): int
    {
        return $this->chunks;
    }

    /**
     * Average upload throughput in MB/s over active write time.
     */
    public function speed(): float
    {
        if ($this->uploadSeconds <= 0.0 || $this->bytes === 0) {
            return 0.0;
        }

        return \round(($this->bytes / $this->uploadSeconds) / 1_000_000, 2);
    }

    /**
     * The run failed: drops what is queued, lets a write in flight end, and
     * waits for the coroutine. Never throws.
     */
    public function abandon(): void
    {
        $this->shipping = false;
        $this->close();
        $this->join();
    }

    private function join(): void
    {
        if ($this->coroutine !== null) {
            Coroutine::join([$this->coroutine]);
        }
    }
}
