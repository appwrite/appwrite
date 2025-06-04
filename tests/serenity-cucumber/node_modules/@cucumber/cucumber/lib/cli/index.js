"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const configuration_1 = require("../configuration");
const api_1 = require("../api");
const i18n_1 = require("./i18n");
const install_validator_1 = require("./install_validator");
const debug_1 = __importDefault(require("debug"));
class Cli {
    constructor({ argv, cwd, stdout, stderr = process.stderr, env, }) {
        this.argv = argv;
        this.cwd = cwd;
        this.stdout = stdout;
        this.stderr = stderr;
        this.env = env;
    }
    async run() {
        const debugEnabled = debug_1.default.enabled('cucumber');
        if (debugEnabled) {
            await (0, install_validator_1.validateInstall)();
        }
        const { options, configuration: argvConfiguration } = configuration_1.ArgvParser.parse(this.argv);
        if (options.i18nLanguages) {
            this.stdout.write((0, i18n_1.getLanguages)());
            return {
                shouldExitImmediately: true,
                success: true,
            };
        }
        if (options.i18nKeywords) {
            this.stdout.write((0, i18n_1.getKeywords)(options.i18nKeywords));
            return {
                shouldExitImmediately: true,
                success: true,
            };
        }
        const environment = {
            cwd: this.cwd,
            stdout: this.stdout,
            stderr: this.stderr,
            env: this.env,
            debug: debugEnabled,
        };
        const { useConfiguration: configuration, runConfiguration } = await (0, api_1.loadConfiguration)({
            file: options.config,
            profiles: options.profile,
            provided: argvConfiguration,
        }, environment);
        const { success } = await (0, api_1.runCucumber)(runConfiguration, environment);
        return {
            shouldExitImmediately: configuration.forceExit,
            success,
        };
    }
}
exports.default = Cli;
//# sourceMappingURL=index.js.map