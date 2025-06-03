import { ensure, isDefined, TinyType } from 'tiny-types';

export class Category extends TinyType {
    static fromJSON(v: string): Category {
        return new Category(v);
    }

    constructor(public readonly value: string) {
        super();
        ensure(this.constructor.name, value, isDefined());
    }
}
