import type { DomainEvent } from '../events';
import { AsyncOperationAttempted, AsyncOperationCompleted, AsyncOperationFailed } from '../events';
import type { CorrelationId, Description, Name } from '../model';
import type { Clock, Duration, TellsTime, Timestamp } from '../screenplay';
import type { ListensToDomainEvents } from '../stage';

/**
 * @group Stage
 */
export class StageManager implements TellsTime {
    private readonly subscribers: ListensToDomainEvents[] = [];
    private readonly wip: WIP;

    constructor(private readonly cueTimeout: Duration, private readonly clock: Clock) {
        this.wip = new WIP(cueTimeout, clock);
    }

    register(...subscribers: ListensToDomainEvents[]): void {
        this.subscribers.push(...subscribers);
    }

    deregister(subscriber: ListensToDomainEvents): void {
        this.subscribers.splice(this.subscribers.indexOf(subscriber), 1);
    }

    notifyOf(event: DomainEvent): void {
        this.wip.recordIfAsync(event);

        this.subscribers.forEach(crewMember => crewMember.notifyOf(event));
    }

    waitForAsyncOperationsToComplete(): Promise<void> {
        return new Promise((resolve, reject) => {

            const timeout = setTimeout(() => {
                clearInterval(interval);

                return resolve();
            }, this.cueTimeout.inMilliseconds());

            const interval = setInterval(() => {
                if (this.wip.hasAllOperationsCompleted()) {
                    clearTimeout(timeout);
                    clearInterval(interval);

                    return resolve();
                }
            }, 10);
        });
    }

    async waitForNextCue(): Promise<void> {

        await this.waitForAsyncOperationsToComplete();

        if (this.wip.hasFailedOperations()) {
            const error = new Error(this.wip.descriptionOfFailedOperations());

            this.wip.resetFailedOperations();

            throw error;
        }

        if (this.wip.hasActiveOperations()) {
            throw new Error(this.wip.descriptionOfTimedOutOperations());
        }
    }

    currentTime(): Timestamp {
        return this.clock.now();
    }
}

/**
 * @package
 */
class WIP {
    private readonly wip = new Map<CorrelationId, AsyncOperationDetails>();
    private readonly failedOperations: FailedAsyncOperationDetails[] = [];

    constructor(
        private readonly cueTimeout: Duration,
        private readonly clock: Clock,
    ) {
    }

    recordIfAsync(event: DomainEvent): void {
        if (event instanceof AsyncOperationAttempted) {
            this.set(event.correlationId, {
                name:           event.name,
                description:    event.description,
                startedAt:      event.timestamp,
            });
        }

        if (event instanceof AsyncOperationCompleted) {
            this.delete(event.correlationId);
        }

        if (event instanceof AsyncOperationFailed) {
            const original = this.get(event.correlationId);

            this.failedOperations.push({
                name:           original.name,
                description:    original.description,
                startedAt:      original.startedAt,
                duration:       event.timestamp.diff(original.startedAt),
                error:          event.error,
            });

            this.delete(event.correlationId)
        }
    }

    hasAllOperationsCompleted(): boolean {
        return this.wip.size === 0;
    }

    hasActiveOperations(): boolean {
        return this.wip.size > 0;
    }

    hasFailedOperations(): boolean {
        return this.failedOperations.length > 0;
    }

    descriptionOfTimedOutOperations(): string {
        const now = this.clock.now();

        return this.activeOperations().reduce(
            (acc, op) => acc.concat(`${ now.diff(op.startedAt) } - [${ op.name.value }] ${ op.description.value }`),
            [`${ this.header(this.wip.size) } within a ${ this.cueTimeout } cue timeout:`],
        ).join('\n');
    }

    descriptionOfFailedOperations() {
        let message = `${ this.header(this.failedOperations.length) }:\n`;

        this.failedOperations.forEach((op: FailedAsyncOperationDetails) => {
            message += `[${ op.name.value }] ${ op.description.value } - ${ op.error.stack }\n---\n`;
        });

        return message;
    }

    resetFailedOperations() {
        this.failedOperations.length = 0;
    }

    private activeOperations() {
        return Array.from(this.wip.values());
    }

    private header(numberOfFailures): string {
        return numberOfFailures === 1
            ? `1 async operation has failed to complete`
            : `${ numberOfFailures } async operations have failed to complete`;
    }

    private set(correlationId: CorrelationId, details: AsyncOperationDetails) {
        return this.wip.set(correlationId, details);
    }

    private get(correlationId: CorrelationId) {
        return this.wip.get(this.asReference(correlationId));
    }

    private delete(correlationId: CorrelationId) {
        this.wip.delete(this.asReference(correlationId))
    }

    private asReference(key: CorrelationId): CorrelationId | undefined {
        for (const [ k, v_ ] of this.wip.entries()) {
            if (k.equals(key)) {
                return k;
            }
        }

        return undefined;   // eslint-disable-line unicorn/no-useless-undefined
    }
}

/**
 * @package
 */
interface AsyncOperationDetails {
    name:           Name;
    description:    Description;
    startedAt:      Timestamp;
    duration?:      Duration;
    error?:         Error;
}

/**
 * @package
 */
interface FailedAsyncOperationDetails {
    name:           Name;
    description:    Description;
    startedAt:      Timestamp;
    duration:       Duration;
    error:          Error;
}
