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
Object.defineProperty(exports, "__esModule", { value: true });
var stream_1 = require("stream");
/**
 * Transforms a stream of message objects to NDJSON
 */
var MessageToNdjsonStream = /** @class */ (function (_super) {
    __extends(MessageToNdjsonStream, _super);
    function MessageToNdjsonStream() {
        return _super.call(this, { writableObjectMode: true, readableObjectMode: false }) || this;
    }
    MessageToNdjsonStream.prototype._transform = function (envelope, encoding, callback) {
        var json = JSON.stringify(envelope);
        this.push(json + '\n');
        callback();
    };
    return MessageToNdjsonStream;
}(stream_1.Transform));
exports.default = MessageToNdjsonStream;
//# sourceMappingURL=MessageToNdjsonStream.js.map