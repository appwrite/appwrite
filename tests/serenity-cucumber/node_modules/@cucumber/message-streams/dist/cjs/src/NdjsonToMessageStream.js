"use strict";
var __extends = (this && this.__extends) || (function () {
    var extendStatics = function (d, b) {
        extendStatics = Object.setPrototypeOf ||
            ({ __proto__: [] } instanceof Array && function (d, b) { d.__proto__ = b; }) ||
            function (d, b) { for (var p in b) if (Object.prototype.hasOwnProperty.call(b, p)) d[p] = b[p]; };
        return extendStatics(d, b);
    };
    return function (d, b) {
        if (typeof b !== "function" && b !== null)
            throw new TypeError("Class extends value " + String(b) + " is not a constructor or null");
        extendStatics(d, b);
        function __() { this.constructor = d; }
        d.prototype = b === null ? Object.create(b) : (__.prototype = b.prototype, new __());
    };
})();
var __values = (this && this.__values) || function(o) {
    var s = typeof Symbol === "function" && Symbol.iterator, m = s && o[s], i = 0;
    if (m) return m.call(o);
    if (o && typeof o.length === "number") return {
        next: function () {
            if (o && i >= o.length) o = void 0;
            return { value: o && o[i++], done: !o };
        }
    };
    throw new TypeError(s ? "Object is not iterable." : "Symbol.iterator is not defined.");
};
Object.defineProperty(exports, "__esModule", { value: true });
var messages_1 = require("@cucumber/messages");
var stream_1 = require("stream");
/**
 * Transforms an NDJSON stream to a stream of message objects
 */
var NdjsonToMessageStream = /** @class */ (function (_super) {
    __extends(NdjsonToMessageStream, _super);
    /**
     * Create a new stream
     *
     * @param parseLine a function that parses a line. This function may ignore a line by returning null.
     */
    function NdjsonToMessageStream(parseLine) {
        if (parseLine === void 0) { parseLine = messages_1.parseEnvelope; }
        var _this = _super.call(this, { writableObjectMode: false, readableObjectMode: true }) || this;
        _this.parseLine = parseLine;
        return _this;
    }
    NdjsonToMessageStream.prototype._transform = function (chunk, encoding, callback) {
        var e_1, _a;
        if (this.buffer === undefined) {
            this.buffer = '';
        }
        this.buffer += Buffer.isBuffer(chunk) ? chunk.toString('utf-8') : chunk;
        var lines = this.buffer.split('\n');
        if (!lines.length) {
            callback();
            return;
        }
        this.buffer = lines.pop();
        try {
            for (var lines_1 = __values(lines), lines_1_1 = lines_1.next(); !lines_1_1.done; lines_1_1 = lines_1.next()) {
                var line = lines_1_1.value;
                if (line.trim().length > 0) {
                    try {
                        var envelope = this.parseLine(line);
                        if (envelope !== null) {
                            this.push(envelope);
                        }
                    }
                    catch (err) {
                        err.message =
                            err.message +
                                "\nNot JSON: '".concat(line, "'\n");
                        return callback(err);
                    }
                }
            }
        }
        catch (e_1_1) { e_1 = { error: e_1_1 }; }
        finally {
            try {
                if (lines_1_1 && !lines_1_1.done && (_a = lines_1.return)) _a.call(lines_1);
            }
            finally { if (e_1) throw e_1.error; }
        }
        callback();
    };
    NdjsonToMessageStream.prototype._flush = function (callback) {
        if (this.buffer) {
            try {
                var object = JSON.parse(this.buffer);
                this.push(object);
            }
            catch (err) {
                return callback(new Error("Not JSONs: ".concat(this.buffer)));
            }
        }
        callback();
    };
    return NdjsonToMessageStream;
}(stream_1.Transform));
exports.default = NdjsonToMessageStream;
//# sourceMappingURL=NdjsonToMessageStream.js.map