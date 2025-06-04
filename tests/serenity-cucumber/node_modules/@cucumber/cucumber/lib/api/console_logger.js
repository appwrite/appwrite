"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.ConsoleLogger = void 0;
const console_1 = require("console");
class ConsoleLogger {
    constructor(stream, debugEnabled) {
        this.stream = stream;
        this.debugEnabled = debugEnabled;
        this.console = new console_1.Console(this.stream);
    }
    debug(...content) {
        if (this.debugEnabled) {
            this.console.debug(...content);
        }
    }
    error(...content) {
        this.console.error(...content);
    }
    warn(...content) {
        this.console.warn(...content);
    }
}
exports.ConsoleLogger = ConsoleLogger;
//# sourceMappingURL=console_logger.js.map