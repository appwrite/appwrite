"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
const UncaughtExceptionManager = {
    registerHandler(handler) {
        process.addListener('uncaughtException', handler);
    },
    unregisterHandler(handler) {
        process.removeListener('uncaughtException', handler);
    },
};
exports.default = UncaughtExceptionManager;
//# sourceMappingURL=uncaught_exception_manager.js.map