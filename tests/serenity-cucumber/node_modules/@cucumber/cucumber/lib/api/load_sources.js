"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.loadSources = void 0;
const paths_1 = require("./paths");
const messages_1 = require("@cucumber/messages");
const environment_1 = require("./environment");
const gherkin_1 = require("./gherkin");
const console_logger_1 = require("./console_logger");
/**
 * Load and parse features, produce a filtered and ordered test plan and/or parse errors.
 *
 * @public
 * @param coordinates - Coordinates required to find features
 * @param environment - Project environment.
 */
async function loadSources(coordinates, environment = {}) {
    const { cwd, stderr, debug } = (0, environment_1.mergeEnvironment)(environment);
    const logger = new console_logger_1.ConsoleLogger(stderr, debug);
    const newId = messages_1.IdGenerator.uuid();
    const { unexpandedFeaturePaths, featurePaths } = await (0, paths_1.resolvePaths)(logger, cwd, coordinates);
    if (featurePaths.length === 0) {
        return {
            plan: [],
            errors: [],
        };
    }
    const { filteredPickles, parseErrors } = await (0, gherkin_1.getFilteredPicklesAndErrors)({
        newId,
        cwd,
        logger,
        unexpandedFeaturePaths,
        featurePaths,
        coordinates,
    });
    const plan = filteredPickles.map(({ location, pickle }) => ({
        name: pickle.name,
        uri: pickle.uri,
        location,
    }));
    const errors = parseErrors.map(({ source, message }) => {
        return {
            uri: source.uri,
            location: source.location,
            message,
        };
    });
    return {
        plan,
        errors,
    };
}
exports.loadSources = loadSources;
//# sourceMappingURL=load_sources.js.map