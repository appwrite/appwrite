import type { TinyType } from 'tiny-types';

import { UnableToRetrieveFeatureFileMap } from './errors';

/**
 * @private
 */
export class Cache<Key extends TinyType, Value> {
    constructor(private store: { [id: string]: Value } = {}) {
    }

    set(key: Key, value: Value): void {
        this.store[this.idOf(key)] = value;
    }

    get(key: Key): Value {
        if (! this.store[this.idOf(key)]) {
            throw new UnableToRetrieveFeatureFileMap(
                `Make sure you cache a value under ${ key.toString() } before trying to retrieve it`,
            );
        }

        return this.store[this.idOf(key)];
    }

    has(key: Key): boolean {
        return !! this.store[this.idOf(key)];
    }

    size(): number {
        return Object.keys(this.store).length;
    }

    clear(): void {
        this.store = {};
    }

    private idOf(key: Key): string {
        return key.toString();
    }
}
