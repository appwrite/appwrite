import type { Answerable } from '../Answerable';
import type { Interaction } from '../Interaction';

/**
 * @group Notes
 */
export interface ChainableSetter<T extends Record<any, any>> {
    set<K extends keyof T>(key: K, value: Answerable<T[K]>): ChainableSetter<T> & Interaction;
}
