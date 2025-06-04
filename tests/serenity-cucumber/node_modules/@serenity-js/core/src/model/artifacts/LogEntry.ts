import { JSONData } from './JSONData';

export class LogEntry extends JSONData {
    static fromJSON(value: { data: string }): LogEntry {
        return new LogEntry(Buffer.from(JSON.stringify(value, undefined, 0), 'utf8').toString('base64'));
    }
}
