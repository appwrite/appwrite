"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const stream_1 = require("stream");
const tmp_1 = __importDefault(require("tmp"));
const fs_1 = __importDefault(require("fs"));
const http_1 = __importDefault(require("http"));
const https_1 = __importDefault(require("https"));
const value_checker_1 = require("../value_checker");
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
class HttpStream extends stream_1.Transform {
    constructor(url, method, headers) {
        super({
            readableObjectMode: true,
        });
        this.url = url;
        this.method = method;
        this.headers = headers;
    }
    _write(chunk, encoding, callback) {
        if (this.tempFile === undefined) {
            tmp_1.default.file((err, name, fd) => {
                if ((0, value_checker_1.doesHaveValue)(err))
                    return callback(err);
                this.tempFilePath = name;
                this.tempFile = fs_1.default.createWriteStream(name, { fd });
                this.tempFile.write(chunk, encoding, callback);
            });
        }
        else {
            this.tempFile.write(chunk, encoding, callback);
        }
    }
    _final(callback) {
        this.tempFile.end(() => {
            this.sendHttpRequest(this.url, this.method, this.headers, (err1, res1) => {
                if ((0, value_checker_1.doesHaveValue)(err1))
                    return callback(err1);
                this.pushResponseBody(res1, () => {
                    this.emitErrorUnlessHttp2xx(res1, this.url, this.method);
                    if (res1.statusCode === 202 &&
                        res1.headers.location !== undefined) {
                        this.sendHttpRequest(res1.headers.location, 'PUT', {}, (err2, res2) => {
                            if ((0, value_checker_1.doesHaveValue)(err2))
                                return callback(err2);
                            this.emitErrorUnlessHttp2xx(res2, this.url, this.method);
                            callback();
                        });
                    }
                    else {
                        callback();
                    }
                });
            });
        });
    }
    pushResponseBody(res, done) {
        let body = Buffer.alloc(0);
        res.on('data', (chunk) => {
            body = Buffer.concat([body, chunk]);
        });
        res.on('end', () => {
            this.push(body.toString('utf-8'));
            done();
        });
    }
    emitErrorUnlessHttp2xx(res, url, method) {
        if (res.statusCode >= 300)
            this.emit('error', new Error(`Unexpected http status ${res.statusCode} from ${method} ${url}`));
    }
    sendHttpRequest(url, method, headers, callback) {
        const httpx = (0, value_checker_1.doesHaveValue)(url.match(/^https:/)) ? https_1.default : http_1.default;
        const additionalHttpHeaders = {};
        const upload = method === 'PUT' || method === 'POST';
        if (upload) {
            additionalHttpHeaders['Content-Length'] = fs_1.default.statSync(this.tempFilePath).size;
        }
        const allHeaders = { ...headers, ...additionalHttpHeaders };
        const req = httpx.request(url, {
            method,
            headers: allHeaders,
        });
        req.on('error', (err) => this.emit('error', err));
        req.on('response', (res) => {
            res.on('error', (err) => this.emit('error', err));
            callback(null, res);
        });
        if (upload) {
            (0, stream_1.pipeline)(fs_1.default.createReadStream(this.tempFilePath), req, (err) => {
                if ((0, value_checker_1.doesHaveValue)(err)) {
                    this.emit('error', err);
                }
            });
        }
        else {
            req.end();
        }
    }
}
exports.default = HttpStream;
//# sourceMappingURL=http_stream.js.map