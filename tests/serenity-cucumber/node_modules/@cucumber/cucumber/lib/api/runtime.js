"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.makeRuntime = void 0;
const runtime_1 = __importDefault(require("../runtime"));
const coordinator_1 = __importDefault(require("../runtime/parallel/coordinator"));
function makeRuntime({ cwd, logger, eventBroadcaster, eventDataCollector, pickleIds, newId, supportCodeLibrary, requireModules, requirePaths, importPaths, options: { parallel, ...options }, }) {
    if (parallel > 0) {
        return new coordinator_1.default({
            cwd,
            logger,
            eventBroadcaster,
            eventDataCollector,
            pickleIds,
            options,
            newId,
            supportCodeLibrary,
            requireModules,
            requirePaths,
            importPaths,
            numberOfWorkers: parallel,
        });
    }
    return new runtime_1.default({
        eventBroadcaster,
        eventDataCollector,
        newId,
        pickleIds,
        supportCodeLibrary,
        options,
    });
}
exports.makeRuntime = makeRuntime;
//# sourceMappingURL=runtime.js.map