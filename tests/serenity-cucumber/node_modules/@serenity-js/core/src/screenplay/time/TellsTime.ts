import type { Timestamp } from './models';

/**
 * Describes an [`Actor`](https://serenity-js.org/api/core/class/Actor/) or a supporting class capable of telling
 * the current wall clock time.
 *
 * ## Learn more
 * - [`Actor`](https://serenity-js.org/api/core/class/Actor/)
 * - [`Serenity`](https://serenity-js.org/api/core/class/Serenity/)
 * - [`Stage`](https://serenity-js.org/api/core/class/Stage/)
 *
 * @group Time
 */
export interface TellsTime {
    /**
     * Returns current wall clock time.
     */
    currentTime(): Timestamp;
}
