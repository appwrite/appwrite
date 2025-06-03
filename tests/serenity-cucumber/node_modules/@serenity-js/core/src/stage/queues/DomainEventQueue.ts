import { ensure, Predicate } from 'tiny-types';

import type { DomainEvent } from '../../events';
import type { CorrelationId } from '../../model';

/**
 * @group Stage
 */
export class DomainEventQueue {
    private readonly queue: Array<DomainEvent & { sceneId: CorrelationId }>;

    constructor(...items: Array<DomainEvent & { sceneId: CorrelationId }>) {
        this.queue = items;
    }

    get sceneId(): CorrelationId {
        ensure('queue', this.queue, isNotEmpty());

        return this.queue[0].sceneId;
    }

    first(): DomainEvent & { sceneId: CorrelationId } {
        return this.queue[0];
    }

    enqueue(event: DomainEvent & { sceneId: CorrelationId }): void {
        this.queue.push(event);
    }

    drain(): Array<DomainEvent & { sceneId: CorrelationId }> {
        return this.queue.splice(0);
    }

    reduce<U>(fn: (previousValue: U, currentValue: DomainEvent & { sceneId: CorrelationId }, currentIndex: number) => U, initialValue: U): U {
        return Array.from(this.queue).reduce(fn, initialValue);
    }
}

function isNotEmpty<T>(): Predicate<T[]> {
    return Predicate.to(`not be empty`, (value: T[]) => Array.isArray(value) && value.length > 0);
}
