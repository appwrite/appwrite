"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
class Definition {
    constructor({ code, id, line, options, unwrappedCode, uri, }) {
        this.code = code;
        this.id = id;
        this.line = line;
        this.options = options;
        this.unwrappedCode = unwrappedCode;
        this.uri = uri;
    }
    buildInvalidCodeLengthMessage(syncOrPromiseLength, callbackLength) {
        return (`function has ${this.code.length.toString()} arguments` +
            `, should have ${syncOrPromiseLength.toString()} (if synchronous or returning a promise)` +
            ` or ${callbackLength.toString()} (if accepting a callback)`);
    }
    baseGetInvalidCodeLengthMessage(parameters) {
        return this.buildInvalidCodeLengthMessage(parameters.length, parameters.length + 1);
    }
}
exports.default = Definition;
//# sourceMappingURL=definition.js.map