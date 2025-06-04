"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.validateConfiguration = void 0;
function validateConfiguration(configuration, logger) {
    if (configuration.publishQuiet) {
        logger.warn('`publishQuiet` option is no longer needed, you can remove it from your configuration; see https://github.com/cucumber/cucumber-js/blob/main/docs/deprecations.md');
    }
    if (configuration.retryTagFilter && !configuration.retry) {
        throw new Error('a positive `retry` count must be specified when setting `retryTagFilter`');
    }
}
exports.validateConfiguration = validateConfiguration;
//# sourceMappingURL=validate_configuration.js.map