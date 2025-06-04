"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const chalk_1 = __importDefault(require("chalk"));
const supports_color_1 = require("supports-color");
const value_checker_1 = require("../value_checker");
function getColorFns(stream, env, enabled) {
    const support = detectSupport(stream, env, enabled);
    if (support) {
        const chalkInstance = new chalk_1.default.Instance(support);
        return {
            forStatus(status) {
                return {
                    AMBIGUOUS: chalkInstance.red.bind(chalk_1.default),
                    FAILED: chalkInstance.red.bind(chalk_1.default),
                    PASSED: chalkInstance.green.bind(chalk_1.default),
                    PENDING: chalkInstance.yellow.bind(chalk_1.default),
                    SKIPPED: chalkInstance.cyan.bind(chalk_1.default),
                    UNDEFINED: chalkInstance.yellow.bind(chalk_1.default),
                    UNKNOWN: chalkInstance.yellow.bind(chalk_1.default),
                }[status];
            },
            location: chalkInstance.gray.bind(chalk_1.default),
            tag: chalkInstance.cyan.bind(chalk_1.default),
            diffAdded: chalkInstance.green.bind(chalk_1.default),
            diffRemoved: chalkInstance.red.bind(chalk_1.default),
            errorMessage: chalkInstance.red.bind(chalk_1.default),
            errorStack: chalkInstance.grey.bind(chalk_1.default),
        };
    }
    else {
        return {
            forStatus(_status) {
                return (x) => x;
            },
            location: (x) => x,
            tag: (x) => x,
            diffAdded: (x) => x,
            diffRemoved: (x) => x,
            errorMessage: (x) => x,
            errorStack: (x) => x,
        };
    }
}
exports.default = getColorFns;
function detectSupport(stream, env, enabled) {
    const support = (0, supports_color_1.supportsColor)(stream);
    // if we find FORCE_COLOR, we can let the supports-color library handle that
    if ('FORCE_COLOR' in env || (0, value_checker_1.doesNotHaveValue)(enabled)) {
        return support;
    }
    return enabled ? support || { level: 1 } : false;
}
//# sourceMappingURL=get_color_fns.js.map