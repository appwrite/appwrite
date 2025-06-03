/// <reference types="node" />
/// <reference types="node" />
/// <reference types="node" />
import { Transform } from 'stream';
import http from 'http';
type HttpMethod = 'GET' | 'POST' | 'PUT';
/**
 * This Writable writes data to a HTTP/HTTPS URL.
 *
 * It has special handling for https://reports.cucumber.io/
 * which uses an API where the first request is a `GET`,
 * and if the response is 202 with a Location header, issues
 * a PUT request to that URL.
 *
 * 3xx redirects are not currently followed.
 */
export default class HttpStream extends Transform {
    private readonly url;
    private readonly method;
    private readonly headers;
    private tempFilePath;
    private tempFile;
    constructor(url: string, method: HttpMethod, headers: http.OutgoingHttpHeaders);
    _write(chunk: any, encoding: BufferEncoding, callback: (err?: Error | null) => void): void;
    _final(callback: (error?: Error | null) => void): void;
    private pushResponseBody;
    private emitErrorUnlessHttp2xx;
    private sendHttpRequest;
}
export {};
