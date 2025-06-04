import { Artifact } from '../Artifact';

/**
 * @public
 * @extends {Artifact}
 */
export class Photo extends Artifact {

    static fromBase64(value: string): Photo {
        return new Photo(value);
    }

    static fromBuffer(value: Buffer | ArrayBuffer): Photo {
        const buffer = value instanceof ArrayBuffer
            ? Buffer.from(value)
            : value;

        return Photo.fromBase64(buffer.toString('base64'));
    }

    /**
     * @param fn
     */
    map<O>(fn: (decodedValue: Buffer) => O): O {
        return fn(Buffer.from(this.base64EncodedValue, 'base64'));
    }
}
