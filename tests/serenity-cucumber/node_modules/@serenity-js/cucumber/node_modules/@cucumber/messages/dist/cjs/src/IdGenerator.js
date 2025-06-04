"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.uuid = uuid;
exports.incrementing = incrementing;
var uuid_1 = require("uuid");
function uuid() {
    return function () { return (0, uuid_1.v4)(); };
}
function incrementing() {
    var next = 0;
    return function () { return (next++).toString(); };
}
//# sourceMappingURL=IdGenerator.js.map