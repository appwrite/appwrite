/**
 * @experimental
 */
export class Config<T extends object> { // eslint-disable-line @typescript-eslint/ban-types
    private readonly transformations = new Map();

    constructor(private readonly config: T) {
    }

    where<K extends keyof T>(fieldName: K, transformation: (value: T[K]) => T[K]): this {
        this.transformations.set(fieldName, transformation);
        return this;
    }

    whereIf<K extends keyof T>(condition: boolean, fieldName: K, transformation: (value: T[K]) => T[K]): this {
        if (condition === true) {
            this.transformations.set(fieldName, transformation);
        }

        return this;
    }

    keys(): string[] {
        return Object.keys(this.config);
    }

    has<K extends keyof T>(key: K): boolean {
        return Object.prototype.hasOwnProperty.call(this.config, key);
    }

    get<K extends keyof T>(key: K): T[K] {
        return this.transformations.has(key)
            ? this.transformations.get(key)(this.config[key])
            : this.config[key];
    }

    getAsList<K extends keyof T>(key: K): Array<ItemOf<T[K]>> {
        const value = this.get(key);

        return value !== null && value !== undefined
            ? [].concat(value)
            : [];
    }

    object(): T {
        return this.keys().reduce((acc, key) => {
            acc[key] = this.get(key as keyof T);
            return acc;
        }, {}) as T;
    }
}

type ItemOf<A> = A extends Array<infer E> ? E : A;
