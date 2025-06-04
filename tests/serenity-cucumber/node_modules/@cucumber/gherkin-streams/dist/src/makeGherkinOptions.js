"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
const messages_1 = require("@cucumber/messages");
const defaultOptions = {
    defaultDialect: 'en',
    includeSource: true,
    includeGherkinDocument: true,
    includePickles: true,
    newId: messages_1.IdGenerator.uuid(),
};
function gherkinOptions(options) {
    return Object.assign(Object.assign({}, defaultOptions), options);
}
exports.default = gherkinOptions;
//# sourceMappingURL=makeGherkinOptions.js.map