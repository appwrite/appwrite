import { LogicError } from '../../errors';
import type { DomainEvent} from '../../events';
import { SceneSequenceDetected, SceneStarts } from '../../events';
import { d } from '../../io/format';
import type { CorrelationId, ScenarioDetails } from '../../model';
import { DomainEventQueue } from './DomainEventQueue';

/**
 * @group Stage
 */
export class DomainEventQueues {

    private readonly queueIndex: Array<{ sceneId: CorrelationId, details: ScenarioDetails, queueId: symbol }> = [];

    private readonly queues: Map<symbol, DomainEventQueue> = new Map();
    private readonly holdingBay = new DomainEventQueue();

    enqueue(event: DomainEvent & { sceneId: CorrelationId }): void {

        if (this.shouldStartNewQueueFor(event)) {
            this.queues.set(this.queueIdFor(event), new DomainEventQueue(event, ...this.holdingBay.drain()));
        } else if (this.hasNoQueuesReadyFor(event)) {
            this.holdingBay.enqueue(event);
        } else {
            this.queues.get(this.queueIdFor(event)).enqueue(event);
        }
    }

    forEach(callback: (queue: DomainEventQueue) => void): void {
        this.queues.forEach(callback);
    }

    drainQueueFor(sceneId: CorrelationId): Array<DomainEvent & { sceneId: CorrelationId }> {
        for (const [key, queue] of this.queues) {
            if (sceneId.equals(queue.sceneId)) {
                const events = queue.drain();
                this.queues.delete(key)

                return events;
            }
        }
        throw new LogicError(d`No domain event queue found for scene ${ sceneId }`);
    }

    private shouldStartNewQueueFor(event: DomainEvent & { sceneId: CorrelationId }) {
        return (event instanceof SceneSequenceDetected || event instanceof SceneStarts)
            && !this.queues.has(this.queueIdFor(event));
    }

    private hasNoQueuesReadyFor(event: DomainEvent & { sceneId: CorrelationId }) {
        return this.queues.size === 0
            || !this.queues.has(this.queueIdFor(event));
    }

    private queueIdFor(event: DomainEvent & { sceneId: CorrelationId, details?: ScenarioDetails }): symbol {
        const exactMatch = this.queueIndex.find(entry => entry.sceneId.equals(event.sceneId));

        if (exactMatch) {
            return exactMatch.queueId;
        }

        if (! (event instanceof SceneStarts)) {
            const sameScenarioMatch = this.queueIndex.find(entry =>
                entry.details &&
                entry.details.equals(event.details),
            );

            if (sameScenarioMatch) {
                this.queueIndex.push({
                    sceneId: event.sceneId,
                    details: event.details || sameScenarioMatch.details,
                    queueId: sameScenarioMatch.queueId,
                });

                return sameScenarioMatch.queueId;
            }
        }

        const newQueueId = Symbol();

        this.queueIndex.push({
            sceneId: event.sceneId,
            details: event.details,
            queueId: newQueueId,
        });

        return newQueueId;
    }
}
