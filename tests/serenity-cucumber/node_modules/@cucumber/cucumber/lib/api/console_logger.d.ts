/// <reference types="node" />
import { Writable } from 'stream';
import { ILogger } from '../logger';
export declare class ConsoleLogger implements ILogger {
    private stream;
    private debugEnabled;
    private console;
    constructor(stream: Writable, debugEnabled: boolean);
    debug(...content: any[]): void;
    error(...content: any[]): void;
    warn(...content: any[]): void;
}
