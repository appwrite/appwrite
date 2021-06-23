declare module 'buffer' {
    import { BinaryLike } from 'crypto';

    export const INSPECT_MAX_BYTES: number;
    export const kMaxLength: number;
    export const kStringMaxLength: number;
    export const constants: {
        MAX_LENGTH: number;
        MAX_STRING_LENGTH: number;
    };
    const BuffType: typeof Buffer;

    export type TranscodeEncoding = "ascii" | "utf8" | "utf16le" | "ucs2" | "latin1" | "binary";

    export function transcode(source: Uint8Array, fromEnc: TranscodeEncoding, toEnc: TranscodeEncoding): Buffer;

    export const SlowBuffer: {
        /** @deprecated since v6.0.0, use `Buffer.allocUnsafeSlow()` */
        new(size: number): Buffer;
        prototype: Buffer;
    };

    export { BuffType as Buffer };

    /**
     * @experimental
     */
    export interface BlobOptions {
        /**
         * @default 'utf8'
         */
        encoding?: BufferEncoding;

        /**
         * The Blob content-type. The intent is for `type` to convey
         * the MIME media type of the data, however no validation of the type format
         * is performed.
         */
        type?: string;
    }

    /**
     * @experimental
     */
    export class Blob {
        /**
         * Returns a promise that fulfills with an {ArrayBuffer} containing a copy of the `Blob` data.
         */
        readonly size: number;

        /**
         * The content-type of the `Blob`.
         */
        readonly type: string;

        /**
         * Creates a new `Blob` object containing a concatenation of the given sources.
         *
         * {ArrayBuffer}, {TypedArray}, {DataView}, and {Buffer} sources are copied into
         * the 'Blob' and can therefore be safely modified after the 'Blob' is created.
         *
         * String sources are also copied into the `Blob`.
         */
        constructor(sources: Array<(BinaryLike | Blob)>, options?: BlobOptions);

        arrayBuffer(): Promise<ArrayBuffer>;

        /**
         * @param start The starting index.
         * @param end The ending index.
         * @param type The content-type for the new `Blob`
         */
        slice(start?: number, end?: number, type?: string): Blob;

        /**
         * Returns a promise that resolves the contents of the `Blob` decoded as a UTF-8 string.
         */
        text(): Promise<string>;
    }
}

declare module 'node:buffer' {
    export * from 'buffer';
}
