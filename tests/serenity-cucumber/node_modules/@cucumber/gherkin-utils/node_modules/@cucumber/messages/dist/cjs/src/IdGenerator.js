"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.incrementing = exports.uuid = void 0;
var uuid_1 = require("uuid");
function uuid() {
    return function () { return (0, uuid_1.v4)(); };
}
exports.uuid = uuid;
function incrementing() {
    var next = 0;
    return function () { return (next++).toString(); };
}
exports.incrementing = incrementing;
//# sourceMappingURL=IdGenerator.js.map