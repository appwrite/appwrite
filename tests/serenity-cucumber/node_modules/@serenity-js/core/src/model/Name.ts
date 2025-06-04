import { ensure, isDefined, TinyType } from 'tiny-types';

export class Name extends TinyType {
    static fromJSON(v: string): Name {
        return new Name(v);
    }

    constructor(public readonly value: string) {
        super();
        ensure(this.constructor.name, value, isDefined());
    }
}
