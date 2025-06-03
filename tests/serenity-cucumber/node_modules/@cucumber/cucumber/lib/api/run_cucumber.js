"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.runCucumber = void 0;
const messages_1 = require("@cucumber/messages");
const events_1 = require("events");
const helpers_1 = require("../formatter/helpers");
const helpers_2 = require("../cli/helpers");
const paths_1 = require("./paths");
const runtime_1 = require("./runtime");
const formatters_1 = require("./formatters");
const support_1 = require("./support");
const environment_1 = require("./environment");
const gherkin_1 = require("./gherkin");
const plugins_1 = require("./plugins");
const console_logger_1 = require("./console_logger");
/**
 * Execute a Cucumber test run.
 *
 * @public
 * @param configuration - Configuration loaded from `loadConfiguration`.
 * @param environment - Project environment.
 * @param onMessage - Callback fired each time Cucumber emits a message.
 */
async function runCucumber(configuration, environment = {}, onMessage) {
    const { cwd, stdout, stderr, env, debug } = (0, environment_1.mergeEnvironment)(environment);
    const logger = new console_logger_1.ConsoleLogger(stderr, debug);
    const newId = messages_1.IdGenerator.uuid();
    const supportCoordinates = 'World' in configuration.support
        ? configuration.support.originalCoordinates
        : configuration.support;
    const { unexpandedFeaturePaths, featurePaths, requirePaths, importPaths } = await (0, paths_1.resolvePaths)(logger, cwd, configuration.sources, supportCoordinates);
    const supportCodeLibrary = 'World' in configuration.support
        ? configuration.support
        : await (0, support_1.getSupportCodeLibrary)({
            cwd,
            newId,
            requirePaths,
            importPaths,
            requireModules: supportCoordinates.requireModules,
        });
    const plugins = await (0, plugins_1.initializePlugins)(logger, configuration, environment);
    const eventBroadcaster = new events_1.EventEmitter();
    if (onMessage) {
        eventBroadcaster.on('envelope', onMessage);
    }
    eventBroadcaster.on('envelope', (value) => plugins.emit('message', value));
    const eventDataCollector = new helpers_1.EventDataCollector(eventBroadcaster);
    let formatterStreamError = false;
    const cleanupFormatters = await (0, formatters_1.initializeFormatters)({
        env,
        cwd,
        stdout,
        stderr,
        logger,
        onStreamError: () => (formatterStreamError = true),
        eventBroadcaster,
        eventDataCollector,
        configuration: configuration.formats,
        supportCodeLibrary,
    });
    await (0, helpers_2.emitMetaMessage)(eventBroadcaster, env);
    let pickleIds = [];
    let parseErrors = [];
    if (featurePaths.length > 0) {
        const gherkinResult = await (0, gherkin_1.getFilteredPicklesAndErrors)({
            newId,
            cwd,
            logger,
            unexpandedFeaturePaths,
            featurePaths,
            coordinates: configuration.sources,
            onEnvelope: (envelope) => eventBroadcaster.emit('envelope', envelope),
        });
        pickleIds = gherkinResult.filteredPickles.map(({ pickle }) => pickle.id);
        parseErrors = gherkinResult.parseErrors;
    }
    if (parseErrors.length) {
        parseErrors.forEach((parseError) => {
            logger.error(`Parse error in "${parseError.source.uri}" ${parseError.message}`);
        });
        await cleanupFormatters();
        await plugins.cleanup();
        return {
            success: false,
            support: supportCodeLibrary,
        };
    }
    (0, helpers_2.emitSupportCodeMessages)({
        eventBroadcaster,
        supportCodeLibrary,
        newId,
    });
    const runtime = (0, runtime_1.makeRuntime)({
        cwd,
        logger,
        eventBroadcaster,
        eventDataCollector,
        pickleIds,
        newId,
        supportCodeLibrary,
        requireModules: supportCoordinates.requireModules,
        requirePaths,
        importPaths,
        options: configuration.runtime,
    });
    const success = await runtime.start();
    await cleanupFormatters();
    await plugins.cleanup();
    return {
        success: success && !formatterStreamError,
        support: supportCodeLibrary,
    };
}
exports.runCucumber = runCucumber;
//# sourceMappingURL=run_cucumber.js.map