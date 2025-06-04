/// <reference types="node" />
import * as messages from '@cucumber/messages';
import { Transform, TransformCallback } from 'stream';
export default class CucumberHtmlStream extends Transform {
    private readonly cssPath;
    private readonly jsPath;
    private template;
    private preMessageWritten;
    private postMessageWritten;
    private firstMessageWritten;
    /**
     * @param cssPath
     * @param jsPath
     */
    constructor(cssPath: string, jsPath: string);
    _transform(envelope: messages.Envelope, encoding: string, callback: TransformCallback): void;
    _flush(callback: TransformCallback): void;
    private writePreMessageUnlessAlreadyWritten;
    private writePostMessage;
    private writeFile;
    private writeTemplateBetween;
    private readTemplate;
    private writeMessage;
}
//# sourceMappingURL=CucumberHtmlStream.d.ts.map