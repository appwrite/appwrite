import { ensure, isDefined, TinyType } from 'tiny-types';

export class Description extends TinyType {
    public readonly value: string;

    static fromJSON(v: string): Description {
        return new Description(v);
    }

    constructor(value: string) {
        super();
        ensure('value', value, isDefined());

        this.value = value.split('\n').map(line => line.trim()).join('\n');
    }
}
