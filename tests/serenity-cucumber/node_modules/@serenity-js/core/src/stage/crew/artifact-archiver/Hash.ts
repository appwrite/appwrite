import { createHash } from 'crypto';
import { ensure, isDefined, TinyType } from 'tiny-types';

/**
 * @package
 */
export class Hash extends TinyType {
    static of(value: string): Hash {
        return new Hash(createHash('sha1').update(value).digest('hex'));
    }

    constructor(public readonly value: string) {
        super();
        ensure(this.constructor.name, value, isDefined());
    }

    long(): string {
        return this.value;
    }

    short(): string {
        return this.value.slice(0, 10);
    }
}
