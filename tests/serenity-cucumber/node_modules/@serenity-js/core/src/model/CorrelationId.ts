import { createId, isCuid } from '@paralleldrive/cuid2';
import { ensure, matches, or, Predicate, TinyType } from 'tiny-types';

export class CorrelationId extends TinyType {
    static fromJSON(v: string): CorrelationId {
        return new CorrelationId(v);
    }

    static create(): CorrelationId {
        return new CorrelationId(createId());
    }

    constructor(public readonly value: string) {
        super();
        ensure(this.constructor.name, value, or(isACuid(), matches(/^[\d.A-Za-z-]+$/)));
    }
}

function isACuid(): Predicate<string> {
    return Predicate.to(`be a Cuid`, (value: string) => isCuid(value));
}
