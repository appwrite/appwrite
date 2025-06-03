"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.loadSupport = void 0;
const messages_1 = require("@cucumber/messages");
const paths_1 = require("./paths");
const support_1 = require("./support");
const environment_1 = require("./environment");
const console_logger_1 = require("./console_logger");
/**
 * Load support code for use in test runs.
 *
 * @public
 * @param options - Subset of `IRunnableConfiguration` required to find the support code.
 * @param environment - Project environment.
 */
async function loadSupport(options, environment = {}) {
    const { cwd, stderr, debug } = (0, environment_1.mergeEnvironment)(environment);
    const logger = new console_logger_1.ConsoleLogger(stderr, debug);
    const newId = messages_1.IdGenerator.uuid();
    const { requirePaths, importPaths } = await (0, paths_1.resolvePaths)(logger, cwd, options.sources, options.support);
    return await (0, support_1.getSupportCodeLibrary)({
        cwd,
        newId,
        requireModules: options.support.requireModules,
        requirePaths,
        importPaths,
    });
}
exports.loadSupport = loadSupport;
//# sourceMappingURL=load_support.js.map