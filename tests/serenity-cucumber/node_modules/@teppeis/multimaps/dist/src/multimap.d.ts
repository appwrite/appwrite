export declare abstract class Multimap<K, V, I extends Iterable<V>> implements Iterable<[K, V]> {
    private size_;
    private map;
    private operator;
    constructor(operator: CollectionOperator<V, I>, iterable?: Iterable<[K, V]>);
    abstract get [Symbol.toStringTag](): string;
    get size(): number;
    get(key: K): I;
    put(key: K, value: V): boolean;
    putAll(key: K, values: I): boolean;
    putAll(multimap: Multimap<K, V, I>): boolean;
    has(key: K): boolean;
    hasEntry(key: K, value: V): boolean;
    delete(key: K): boolean;
    deleteEntry(key: K, value: V): boolean;
    clear(): void;
    keys(): IterableIterator<K>;
    entries(): IterableIterator<[K, V]>;
    values(): IterableIterator<V>;
    forEach<T>(callback: (this: T | this, alue: V, key: K, map: this) => void, thisArg?: T): void;
    [Symbol.iterator](): IterableIterator<[K, V]>;
    asMap(): Map<K, I>;
}
export interface CollectionOperator<V, I> {
    create(): I;
    clone(collection: I): I;
    add(value: V, collection: I): boolean;
    size(collection: I): number;
    delete(value: V, collection: I): boolean;
    has(value: V, collection: I): boolean;
}
