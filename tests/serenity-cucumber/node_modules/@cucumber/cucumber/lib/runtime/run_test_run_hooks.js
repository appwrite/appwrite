"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.makeRunTestRunHooks = void 0;
const user_code_runner_1 = __importDefault(require("../user_code_runner"));
const verror_1 = __importDefault(require("verror"));
const helpers_1 = require("../formatter/helpers");
const value_checker_1 = require("../value_checker");
const makeRunTestRunHooks = (dryRun, defaultTimeout, errorMessage) => dryRun
    ? async () => { }
    : async (definitions, name) => {
        for (const hookDefinition of definitions) {
            const { error } = await user_code_runner_1.default.run({
                argsArray: [],
                fn: hookDefinition.code,
                thisArg: null,
                timeoutInMilliseconds: (0, value_checker_1.valueOrDefault)(hookDefinition.options.timeout, defaultTimeout),
            });
            if ((0, value_checker_1.doesHaveValue)(error)) {
                const location = (0, helpers_1.formatLocation)(hookDefinition);
                throw new verror_1.default(error, errorMessage(name, location));
            }
        }
    };
exports.makeRunTestRunHooks = makeRunTestRunHooks;
//# sourceMappingURL=run_test_run_hooks.js.map