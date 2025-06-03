"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
const value_checker_1 = require("../value_checker");
class Formatter {
    constructor(options) {
        this.colorFns = options.colorFns;
        this.cwd = options.cwd;
        this.eventDataCollector = options.eventDataCollector;
        this.log = options.log;
        this.snippetBuilder = options.snippetBuilder;
        this.stream = options.stream;
        this.supportCodeLibrary = options.supportCodeLibrary;
        this.cleanup = options.cleanup;
        this.printAttachments = (0, value_checker_1.valueOrDefault)(options.parsedArgvOptions.printAttachments, true);
    }
    async finished() {
        await this.cleanup();
    }
}
exports.default = Formatter;
//# sourceMappingURL=index.js.map