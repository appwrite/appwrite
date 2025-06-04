import { JSONData } from './JSONData';

export class TextData extends JSONData {
    static fromJSON(value: { contentType: string, data: string }): TextData {
        return new TextData(Buffer.from(JSON.stringify(value, undefined, 0), 'utf8').toString('base64'));
    }
}
