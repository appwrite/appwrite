"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const fs_1 = __importDefault(require("fs"));
const stream_1 = require("stream");
class CucumberHtmlStream extends stream_1.Transform {
    /**
     * @param cssPath
     * @param jsPath
     */
    constructor(cssPath, jsPath) {
        super({ objectMode: true });
        this.cssPath = cssPath;
        this.jsPath = jsPath;
        this.template = null;
        this.preMessageWritten = false;
        this.postMessageWritten = false;
        this.firstMessageWritten = false;
    }
    _transform(envelope, encoding, callback) {
        if (this.postMessageWritten) {
            return callback(new Error('Stream closed'));
        }
        this.writePreMessageUnlessAlreadyWritten((err) => {
            if (err)
                return callback(err);
            this.writeMessage(envelope);
            callback();
        });
    }
    _flush(callback) {
        this.writePostMessage(callback);
    }
    writePreMessageUnlessAlreadyWritten(callback) {
        if (this.preMessageWritten) {
            return callback();
        }
        this.preMessageWritten = true;
        this.writeTemplateBetween(null, '{{css}}', (err) => {
            if (err)
                return callback(err);
            this.writeFile(this.cssPath, (err) => {
                if (err)
                    return callback(err);
                this.writeTemplateBetween('{{css}}', '{{messages}}', (err) => {
                    if (err)
                        return callback(err);
                    callback();
                });
            });
        });
    }
    writePostMessage(callback) {
        this.writePreMessageUnlessAlreadyWritten((err) => {
            if (err)
                return callback(err);
            this.writeTemplateBetween('{{messages}}', '{{script}}', (err) => {
                if (err)
                    return callback(err);
                this.writeFile(this.jsPath, (err) => {
                    if (err)
                        return callback(err);
                    this.writeTemplateBetween('{{script}}', null, callback);
                });
            });
        });
    }
    writeFile(path, callback) {
        const cssStream = fs_1.default.createReadStream(path, { encoding: 'utf-8' });
        cssStream.on('data', (chunk) => this.push(chunk));
        cssStream.on('error', (err) => callback(err));
        cssStream.on('end', callback);
    }
    writeTemplateBetween(begin, end, callback) {
        this.readTemplate((err, template) => {
            if (err)
                return callback(err);
            if (!template)
                return callback(new Error('template is required if error is missing'));
            const beginIndex = begin == null ? 0 : template.indexOf(begin) + begin.length;
            const endIndex = end == null ? template.length : template.indexOf(end);
            this.push(template.substring(beginIndex, endIndex));
            callback();
        });
    }
    readTemplate(callback) {
        if (this.template !== null) {
            return callback(null, this.template);
        }
        fs_1.default.readFile(__dirname + '/index.mustache.html', { encoding: 'utf-8' }, (err, template) => {
            if (err)
                return callback(err);
            this.template = template;
            return callback(null, template);
        });
    }
    writeMessage(envelope) {
        if (!this.firstMessageWritten) {
            this.firstMessageWritten = true;
        }
        else {
            this.push(',');
        }
        this.push(JSON.stringify(envelope));
    }
}
exports.default = CucumberHtmlStream;
//# sourceMappingURL=CucumberHtmlStream.js.map